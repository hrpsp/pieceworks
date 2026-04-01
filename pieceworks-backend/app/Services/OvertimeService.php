<?php

namespace App\Services;

use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OvertimeService
{
    private int   $weeklyRegularHours;
    private int   $shiftHours;
    private float $otMultiplier;

    public function __construct()
    {
        $this->weeklyRegularHours = (int)   config('payroll.weekly_regular_hours', 48);
        $this->shiftHours         = (int)   config('payroll.shift_hours', 8);
        $this->otMultiplier       = (float) config('payroll.ot_multiplier', 1.0);
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Calculate overtime for a worker in an ISO week.
     *
     * Strategy:
     *   - Treat each unique (work_date × shift) combination as one 8-hour block.
     *   - Blocks beyond weeklyRegularHours/shiftHours cap are counted as OT.
     *   - Separately, records whose shift_adjustments.overtime_flagged = true
     *     contribute their shift_adjustment amount as confirmed OT premium.
     *
     * Returns:
     * [
     *   regular_blocks     => int,   (distinct date+shift combos within 48h)
     *   ot_blocks          => int,   (blocks beyond 48h)
     *   ot_hours           => float,
     *   regular_pairs      => int,
     *   ot_pairs           => int,   (from flagged shift_adjustments)
     *   ot_premium_pkr     => float, (confirmed OT premium stored in shift_adjustment)
     *   computed_ot_pkr    => float, (calculated from ot_pairs × rate × multiplier)
     * ]
     */
    public function calculateWeeklyOT(int $workerId, string $weekRef): array
    {
        [$startDate, $endDate] = $this->weekBounds($weekRef);

        // Load all production records for the week
        $records = DB::table('production_records')
            ->where('worker_id', $workerId)
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('validation_status', ['rejected'])
            ->orderBy('work_date')
            ->orderByRaw("FIELD(shift,'morning','afternoon','night')")
            ->get(['id', 'work_date', 'shift', 'pairs_produced', 'rate_amount', 'shift_adjustment']);

        // Count distinct (date, shift) pairs → each = 8h block
        $distinctBlocks = $records
            ->map(fn ($r) => $r->work_date . '|' . $r->shift)
            ->unique()
            ->count();

        $regularCapBlocks = intdiv($this->weeklyRegularHours, $this->shiftHours); // 48 / 8 = 6
        $otBlocks         = max(0, $distinctBlocks - $regularCapBlocks);
        $otHours          = $otBlocks * $this->shiftHours;

        // OT pairs: from records linked to flagged shift_adjustments
        $flaggedRecordIds = DB::table('shift_adjustments')
            ->where('worker_id', $workerId)
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('overtime_flagged', true)
            ->whereNotNull('confirmed_at')
            ->pluck('production_record_id')
            ->filter()
            ->all();

        $otRecords = $records->whereIn('id', $flaggedRecordIds);
        $otPairs   = (int) $otRecords->sum('pairs_produced');

        // Confirmed OT premium already stored in shift_adjustment column
        $confirmedOtPremium = (float) $records->sum('shift_adjustment');

        // Computed OT premium from OT pairs (for display/audit, may differ from confirmed)
        $computedOtPkr = (float) $otRecords->sum(
            fn ($r) => (float) $r->pairs_produced * (float) $r->rate_amount * $this->otMultiplier
        );

        $regularPairs = (int) $records->whereNotIn('id', $flaggedRecordIds)->sum('pairs_produced');

        return [
            'week_ref'           => $weekRef,
            'worker_id'          => $workerId,
            'regular_blocks'     => min($distinctBlocks, $regularCapBlocks),
            'ot_blocks'          => $otBlocks,
            'ot_hours'           => $otHours,
            'regular_pairs'      => $regularPairs,
            'ot_pairs'           => $otPairs,
            'ot_premium_pkr'     => round($confirmedOtPremium, 2),
            'computed_ot_pkr'    => round($computedOtPkr, 2),
        ];
    }

    /**
     * Determine the shift allowance for a single work_date / actual_shift.
     *
     * Rules:
     *   1. If scheduled shift is night but worker actually works morning/afternoon
     *      → forfeit the night premium differential
     *   2. If gap from last shift < callin_threshold_hours
     *      → apply call-in minimum guarantee
     *   3. Otherwise → standard shift allowance (pro-rated as 1/6 of weekly amount)
     *
     * Returns PKR amount for that single shift slot.
     */
    public function applyShiftAllowance(int $workerId, string|Carbon $workDate, string $actualShift): float
    {
        $date = $workDate instanceof Carbon ? $workDate : Carbon::parse($workDate);

        $weeklyStandard = (float) config('payroll.shift_allowance_per_worker', 500.00);
        $nightAllowance = (float) config('payroll.night_shift_allowance', 750.00);
        $callInMin      = (int)   config('payroll.callin_min_hours', 4);
        $callInThresh   = (int)   config('payroll.callin_threshold_hours', 4);
        $minWeeklyWage  = (float) config('payroll.minimum_weekly_wage', 8_545.00);

        // Pro-rate per shift (1 of up to 6 regular shifts per week)
        $shiftAllowance = round($weeklyStandard / 6, 2);

        // Scheduled shift for this date
        $scheduledShift = DB::table('shift_schedules')
            ->where('worker_id', $workerId)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString()))
            ->orderByDesc('effective_from')
            ->value('shift')
            ?? DB::table('workers')->where('id', $workerId)->value('default_shift');

        // Rule 1: Night-to-day swap → forfeit night differential
        $nightDifferential = round(($nightAllowance - $weeklyStandard) / 6, 2);
        if ($scheduledShift === 'night' && $actualShift !== 'night') {
            $shiftAllowance -= $nightDifferential;
        }

        // Rule 2: Called in with < callin_threshold gap → minimum guarantee
        $gapHours = $this->getGapHours($workerId, $date, $actualShift);
        if ($gapHours !== null && $gapHours < $callInThresh) {
            // Guarantee: callin_min_hours × (min weekly wage / 48 regular hours)
            $hourlyEquiv = $minWeeklyWage / 48;
            $callInGuarantee = round($callInMin * $hourlyEquiv, 2);
            // Return whichever is greater
            $shiftAllowance = max($shiftAllowance, $callInGuarantee);
        }

        return max(0.0, $shiftAllowance);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function getGapHours(int $workerId, Carbon $date, string $actualShift): ?float
    {
        $shiftTimes = config('payroll.shift_times');
        $lastRecord = DB::table('production_records')
            ->where('worker_id', $workerId)
            ->where(fn ($q) => $q
                ->where('work_date', '<', $date->toDateString())
                ->orWhere(fn ($inner) => $inner
                    ->where('work_date', $date->toDateString())
                    ->whereRaw('FIELD(shift,"morning","afternoon","night") < FIELD(?, "morning","afternoon","night")', [$actualShift])
                )
            )
            ->whereNotIn('validation_status', ['rejected'])
            ->orderByDesc('work_date')
            ->orderByRaw('FIELD(shift,"night","afternoon","morning")')
            ->first(['work_date', 'shift']);

        if (! $lastRecord) {
            return null;
        }

        $endTime = $lastRecord->shift;
        $endStr  = $shiftTimes[$endTime]['end'] ?? '15:00';
        $lastEnd = Carbon::parse("{$lastRecord->work_date} {$endStr}");
        if ($endTime === 'night') {
            $lastEnd->addDay();
        }

        $startStr   = $shiftTimes[$actualShift]['start'] ?? '07:00';
        $thisStart  = Carbon::parse("{$date->toDateString()} {$startStr}");
        $gapMinutes = $lastEnd->diffInMinutes($thisStart, false);

        return max(0.0, round($gapMinutes / 60, 2));
    }

    private function weekBounds(string $weekRef): array
    {
        [$year, $isoWeek] = explode('-W', $weekRef);
        $monday   = Carbon::now()->setISODate((int) $year, (int) $isoWeek)->startOfDay();
        $saturday = $monday->copy()->addDays(5);
        return [$monday, $saturday];
    }
}
