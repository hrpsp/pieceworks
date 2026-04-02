<?php

namespace App\Services;

use App\Models\ProductionRecord;
use App\Models\ShiftAdjustment;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShiftAdjustmentService
{
    /**
     * Shift start / end times as Carbon-parseable strings.
     * Night shift ends at 07:00 the *next* day — handled via addDay() where needed.
     */
    private array $shiftTimes;
    private int   $minGapHours;

    public function __construct()
    {
        $this->shiftTimes  = config('pieceworks.shift_times');
        $this->minGapHours = (int) config('pieceworks.min_gap_hours', 8);
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Analyse whether placing a worker on $actualShift on $workDate is an adjustment
     * from their scheduled shift, and whether the rest gap triggers an OT flag.
     *
     * Returns:
     * [
     *   is_adjustment   => bool,
     *   scheduled_shift => string|null,
     *   gap_hours       => float|null,
     *   ot_flagged      => bool,
     * ]
     */
    public function detectAdjustment(
        int $workerId,
        string|Carbon $workDate,
        string $actualShift,
        int $lineId
    ): array {
        $date = $workDate instanceof Carbon ? $workDate : Carbon::parse($workDate);

        // ── 1. Find scheduled shift ─────────────────────────────────────────
        $scheduledShift = $this->resolveScheduledShift($workerId, $date);

        $isAdjustment = $scheduledShift !== null && $scheduledShift !== $actualShift;

        // ── 2. Find the last shift end time for this worker ─────────────────
        [$gapHours, $lastShift, $lastDate] = $this->computeGap($workerId, $date, $actualShift);

        // ── 3. OT flag: gap below minimum rest threshold ────────────────────
        $otFlagged = $gapHours !== null && $gapHours < $this->minGapHours;

        return [
            'is_adjustment'   => $isAdjustment,
            'scheduled_shift' => $scheduledShift,
            'gap_hours'       => $gapHours,
            'ot_flagged'      => $otFlagged,
            'last_shift'      => $lastShift,
            'last_work_date'  => $lastDate,
        ];
    }

    /**
     * Called after a production record is created.
     * Creates a shift_adjustments row if an adjustment or OT flag was detected.
     *
     * Returns the created ShiftAdjustment or null if no adjustment detected.
     */
    public function detectAndRecord(ProductionRecord $record): ?ShiftAdjustment
    {
        $result = $this->detectAdjustment(
            $record->worker_id,
            $record->work_date,
            $record->shift,
            $record->line_id
        );

        // Nothing to record if no adjustment and no OT flag
        if (! $result['is_adjustment'] && ! $result['ot_flagged']) {
            return null;
        }

        // Upsert: one adjustment record per worker per date
        // (multiple shifts on same day may update the same row)
        return ShiftAdjustment::updateOrCreate(
            [
                'worker_id' => $record->worker_id,
                'work_date' => $record->work_date->toDateString(),
            ],
            [
                'production_record_id'    => $record->id,
                'actual_shift'            => $record->shift,
                'scheduled_shift'         => $result['scheduled_shift'] ?? $record->shift,
                'line_id'                 => $record->line_id,
                'hours_gap_from_last_shift' => $result['gap_hours'],
                'overtime_flagged'        => $result['ot_flagged'],
                // reason / confirmed_at remain null until supervisor confirms
                'reason'                  => 'line_shortage', // default placeholder
            ]
        );
    }

    /**
     * Supervisor confirms a pending adjustment, providing the authorisation reason.
     * If the adjustment is overtime, marks paired production records with OT premium.
     */
    public function confirmAdjustment(
        int $productionRecordId,
        int $authorizedBy,
        string $reason,
        string $reasonCode = 'line_shortage'
    ): ShiftAdjustment {
        $record = ProductionRecord::findOrFail($productionRecordId);

        $adjustment = ShiftAdjustment::where('production_record_id', $productionRecordId)
            ->orWhere(fn ($q) => $q
                ->where('worker_id', $record->worker_id)
                ->where('work_date', $record->work_date->toDateString())
            )
            ->firstOrFail();

        $adjustment->update([
            'authorized_by' => $authorizedBy,
            'reason'        => $reasonCode,
            'reason_text'   => $reason,
            'confirmed_at'  => now(),
        ]);

        // If OT flagged: apply OT premium to the production record's shift_adjustment field
        if ($adjustment->overtime_flagged) {
            $this->applyOtPremium($record);
        }

        return $adjustment->fresh(['worker', 'productionRecord', 'authorizer']);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Look up the worker's currently effective scheduled shift on the given date.
     * Falls back to worker.default_shift if no schedule row exists.
     */
    private function resolveScheduledShift(int $workerId, Carbon $date): ?string
    {
        $schedule = DB::table('shift_schedules')
            ->where('worker_id', $workerId)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $date->toDateString())
            )
            ->orderByDesc('effective_from')
            ->value('shift');

        if ($schedule) {
            return $schedule;
        }

        // Fallback to worker's default shift
        return DB::table('workers')
            ->where('id', $workerId)
            ->value('default_shift');
    }

    /**
     * Find the most recent shift this worker completed before the given date+shift,
     * and compute hours of rest gap.
     *
     * Returns [gap_hours|null, last_shift|null, last_date|null]
     */
    private function computeGap(int $workerId, Carbon $date, string $actualShift): array
    {
        $shiftOrder = ['morning' => 0, 'afternoon' => 1, 'night' => 2];

        // Find the last production record for this worker BEFORE this shift
        $lastRecord = DB::table('production_records')
            ->where('worker_id', $workerId)
            ->where(fn ($q) => $q
                // Earlier date
                ->where('work_date', '<', $date->toDateString())
                // Same date but earlier shift
                ->orWhere(fn ($inner) => $inner
                    ->where('work_date', $date->toDateString())
                    ->where('shift', '!=', $actualShift)
                    ->whereRaw('FIELD(shift, "morning", "afternoon", "night") < FIELD(?, "morning", "afternoon", "night")', [$actualShift])
                )
            )
            ->whereNotIn('validation_status', ['rejected'])
            ->orderByDesc('work_date')
            ->orderByRaw('FIELD(shift, "night", "afternoon", "morning")')
            ->first(['work_date', 'shift']);

        if (! $lastRecord) {
            return [null, null, null];
        }

        // Compute end time of last shift
        $lastEndTime   = $this->shiftEndCarbon($lastRecord->work_date, $lastRecord->shift);
        // Compute start time of actual shift
        $thisStartTime = $this->shiftStartCarbon($date->toDateString(), $actualShift);

        $gapMinutes = $lastEndTime->diffInMinutes($thisStartTime, false);

        // Negative gap means they overlap (same shift boundary) — treat as 0
        $gapHours = max(0, round($gapMinutes / 60, 2));

        return [$gapHours, $lastRecord->shift, $lastRecord->work_date];
    }

    /**
     * Returns the Carbon datetime of when a given shift starts on a given date.
     */
    private function shiftStartCarbon(string $date, string $shift): Carbon
    {
        $time = $this->shiftTimes[$shift]['start'] ?? '07:00';
        return Carbon::parse("{$date} {$time}");
    }

    /**
     * Returns the Carbon datetime of when a given shift ends.
     * Night shift ends at 07:00 the NEXT day.
     */
    private function shiftEndCarbon(string $date, string $shift): Carbon
    {
        $time  = $this->shiftTimes[$shift]['end'] ?? '15:00';
        $end   = Carbon::parse("{$date} {$time}");

        if ($shift === 'night') {
            $end->addDay(); // night shift: ends next morning
        }

        return $end;
    }

    /**
     * Apply OT premium to the production record's shift_adjustment column.
     * Method B: OT premium = pairs × rate × ot_multiplier (100% extra on top of base).
     */
    private function applyOtPremium(ProductionRecord $record): void
    {
        $multiplier = (float) config('pieceworks.ot_multiplier', 1.0);
        $otPremium  = round((float) $record->pairs_produced * (float) $record->rate_amount * $multiplier, 2);

        $record->updateQuietly([
            'shift_adjustment' => $otPremium,
        ]);
    }
}
