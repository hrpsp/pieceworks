<?php

namespace App\Services;

use App\Models\GhostWorkerFlag;
use App\Models\PayrollException;
use App\Models\ProductionRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GhostWorkerService
{
    private const ANOMALY_STD_DEV_THRESHOLD = 2.0;
    private const MIN_HISTORY_RECORDS = 3; // Need at least this many records to compute anomaly

    /**
     * Analyse a single worker's ghost risk for a given date.
     *
     * Returns:
     * [
     *   biometric_present  => bool,
     *   production_anomaly => bool,
     *   risk_level         => 'none'|'low'|'medium'|'high',
     *   pairs_produced     => int|null,
     *   four_week_avg      => float|null,
     *   std_dev            => float|null,
     * ]
     */
    public function checkGhostRisk(int $workerId, string|Carbon $workDate, int $pairsProduced = 0): array
    {
        $date = $workDate instanceof Carbon ? $workDate : Carbon::parse($workDate);

        // ── 1. Biometric check ─────────────────────────────────────────────
        $biometricPresent = DB::table('biometric_records')
            ->where('worker_id', $workerId)
            ->whereDate('punch_time', $date)
            ->exists();

        // ── 2. Also accept manual attendance record as presence confirmation ─
        if (!$biometricPresent) {
            $biometricPresent = DB::table('attendance_records')
                ->where('worker_id', $workerId)
                ->where('work_date', $date->toDateString())
                ->whereIn('status', ['present'])
                ->exists();
        }

        // ── 3. Production anomaly: compare against 4-week rolling avg ─────
        $fourWeeksAgo = $date->copy()->subWeeks(4)->startOfDay();

        $history = DB::table('production_records')
            ->where('worker_id', $workerId)
            ->whereBetween('work_date', [$fourWeeksAgo, $date->copy()->subDay()])
            ->whereNotIn('validation_status', ['rejected'])
            ->selectRaw('SUM(pairs_produced) as day_total')
            ->groupBy('work_date')
            ->pluck('day_total')
            ->map(fn ($v) => (float) $v)
            ->values()
            ->all();

        $productionAnomaly = false;
        $fourWeekAvg       = null;
        $stdDev            = null;

        if (count($history) >= self::MIN_HISTORY_RECORDS) {
            $n           = count($history);
            $fourWeekAvg = array_sum($history) / $n;
            $variance    = array_sum(array_map(fn($x) => ($x - $fourWeekAvg) ** 2, $history)) / $n;
            $stdDev      = sqrt($variance);

            if ($stdDev > 0) {
                $zScore            = abs($pairsProduced - $fourWeekAvg) / $stdDev;
                $productionAnomaly = $zScore > self::ANOMALY_STD_DEV_THRESHOLD;
            }
        }

        // ── 4. Determine risk level ────────────────────────────────────────
        // High: biometric absent AND production is being entered
        // Medium: biometric present but statistical anomaly
        // Low: biometric present, no anomaly
        // None: no production being entered
        $riskLevel = 'none';

        if ($pairsProduced > 0) {
            if (!$biometricPresent) {
                $riskLevel = 'high';
            } elseif ($productionAnomaly) {
                $riskLevel = 'medium';
            } else {
                $riskLevel = 'low';
            }
        }

        return [
            'biometric_present'  => $biometricPresent,
            'production_anomaly' => $productionAnomaly,
            'risk_level'         => $riskLevel,
            'pairs_produced'     => $pairsProduced,
            'four_week_avg'      => $fourWeekAvg ? round($fourWeekAvg, 2) : null,
            'std_dev'            => $stdDev ? round($stdDev, 2) : null,
        ];
    }

    /**
     * Persist a ghost worker flag row.
     * Called after production record is created so we have a record_id.
     */
    public function raiseFlag(
        int $workerId,
        int $productionRecordId,
        string $workDate,
        array $riskData
    ): GhostWorkerFlag {
        return GhostWorkerFlag::create([
            'worker_id'            => $workerId,
            'production_record_id' => $productionRecordId,
            'work_date'            => $workDate,
            'risk_level'           => $riskData['risk_level'],
            'biometric_present'    => $riskData['biometric_present'],
            'production_anomaly'   => $riskData['production_anomaly'],
            'pairs_produced'       => $riskData['pairs_produced'],
            'four_week_avg'        => $riskData['four_week_avg'],
            'std_dev'              => $riskData['std_dev'],
        ]);
    }

    /**
     * Create a PayrollException to notify payroll manager of a high-risk ghost flag.
     * Uses type 'manual' with a clear description since the exception_type enum
     * is fixed and does not include ghost_worker.
     */
    public function createPayrollException(
        int $workerId,
        int $productionRecordId,
        string $workDate,
        array $riskData
    ): void {
        PayrollException::create([
            'payroll_run_id'             => null,
            'worker_id'                  => $workerId,
            'worker_weekly_payroll_id'   => null,
            'exception_type'             => 'manual',
            'description'                => sprintf(
                'Ghost worker flag (HIGH risk) on %s — biometric: %s, pairs: %d, 4w avg: %s. Production record #%d held for review.',
                $workDate,
                $riskData['biometric_present'] ? 'present' : 'ABSENT',
                $riskData['pairs_produced'],
                $riskData['four_week_avg'] ?? 'N/A',
                $productionRecordId
            ),
            'amount'          => null,
            'resolved_at'     => null,
            'resolved_by'     => null,
            'resolution_note' => null,
        ]);
    }

    /**
     * Mark a flag as overridden (manually cleared by authorised user).
     */
    public function overrideFlag(GhostWorkerFlag $flag, int $userId, string $reason): void
    {
        $flag->update([
            'overridden_at'  => now(),
            'overridden_by'  => $userId,
            'override_reason'=> $reason,
            'resolved_at'    => now(),
        ]);

        // If the production record was held (flagged), restore it to pending
        if ($flag->production_record_id) {
            ProductionRecord::where('id', $flag->production_record_id)
                ->where('validation_status', 'flagged')
                ->update([
                    'validation_status' => 'pending',
                    'ghost_risk_level'  => 'low', // downgraded after override
                ]);
        }
    }
}
