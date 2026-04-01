<?php

namespace App\Services;

use App\Models\ContractorPerformanceScore;
use App\Models\ContractorSettlement;
use App\Models\GhostWorkerFlag;
use App\Models\Line;
use App\Models\LineTarget;
use App\Models\ProductionRecord;
use App\Models\QcRejection;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use App\Models\WorkerWeeklyPayroll;
use Carbon\Carbon;

class ContractorSettlementService
{
    // Composite score weights (must sum to 1.0)
    private const WEIGHT_DELIVERY    = 0.40;
    private const WEIGHT_QUALITY     = 0.30;
    private const WEIGHT_COMPLIANCE  = 0.30;

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Calculate (or recalculate) the settlement for a contractor in a payroll run.
     *
     * billing_contractor_id determines which contractor is charged for each production
     * record (set by the observer to the line's contractor). NULL records fall back
     * to the worker's own contractor_id for backward compatibility.
     *
     * Returns the persisted ContractorSettlement as an array.
     */
    public function calculateSettlement(int $contractorId, int $payrollRunId): array
    {
        $run = WeeklyPayrollRun::findOrFail($payrollRunId);

        // ── Production billed to this contractor ────────────────────────────
        // A record is billed here if:
        //   a) billing_contractor_id = contractorId  (explicit — includes cross-contractor work)
        //   b) billing_contractor_id IS NULL AND worker.contractor_id = contractorId  (legacy rows)
        $totals = ProductionRecord::whereNotIn('validation_status', ['rejected', 'voided'])
            ->whereBetween('work_date', [
                $run->start_date->toDateString(),
                $run->end_date->toDateString(),
            ])
            ->where(function ($q) use ($contractorId) {
                $q->where('billing_contractor_id', $contractorId)
                  ->orWhere(function ($sub) use ($contractorId) {
                      $sub->whereNull('billing_contractor_id')
                          ->whereHas('worker', fn ($w) => $w->where('contractor_id', $contractorId));
                  });
            })
            ->selectRaw('
                COALESCE(SUM(pairs_produced), 0) AS total_pairs,
                COALESCE(SUM(gross_earnings),  0) AS bata_owes
            ')
            ->first();

        $totalPairs = (int)   ($totals?->total_pairs ?? 0);
        $bataOwes   = (float) ($totals?->bata_owes   ?? 0.0);

        // ── What this contractor's own workers were actually paid ────────────
        $workersPaid = (float) WorkerWeeklyPayroll::where('payroll_run_id', $payrollRunId)
            ->whereHas('worker', fn ($q) => $q->where('contractor_id', $contractorId))
            ->sum('net_pay');

        $margin            = round($bataOwes - $workersPaid, 2);
        $contractedRateAvg = $totalPairs > 0 ? round($bataOwes / $totalPairs, 4) : null;

        $settlement = ContractorSettlement::updateOrCreate(
            ['contractor_id' => $contractorId, 'payroll_run_id' => $payrollRunId],
            [
                'week_ref'            => $run->week_ref,
                'total_pairs'         => $totalPairs,
                'contracted_rate_avg' => $contractedRateAvg,
                'bata_owes'           => $bataOwes,
                'workers_paid'        => $workersPaid,
                'contractor_margin'   => $margin,
                'settlement_status'   => 'pending',
            ]
        );

        return $settlement->fresh()->toArray();
    }

    /**
     * Calculate (or recalculate) the weekly performance score for a contractor.
     *
     * Scores:
     *   delivery_score    – actual pairs / target pairs × 100  (capped at 150)
     *   rejection_rate    – rejected pairs / actual pairs  (ratio 0-1, stored as decimal)
     *   compliance_score  – EOBI+PESSI registered / total active workers × 100
     *   composite_score   – weighted average of delivery (40%), quality (30%), compliance (30%)
     *
     * Returns the persisted score row + a 'detail' breakdown.
     */
    public function calculateContractorPerformanceScore(int $contractorId, string $weekRef): array
    {
        [$startDate, $endDate] = $this->weekBounds($weekRef);

        $workerIds    = Worker::where('contractor_id', $contractorId)
            ->where('status', 'active')
            ->pluck('id');
        $totalWorkers = $workerIds->count();

        if ($totalWorkers === 0) {
            return ['error' => 'No active workers found for this contractor.'];
        }

        // ── Delivery score ───────────────────────────────────────────────────
        $lineIds = Line::where('default_contractor_id', $contractorId)
            ->pluck('id');

        $actualPairs = (int) ProductionRecord::whereIn('worker_id', $workerIds)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereNotIn('validation_status', ['rejected', 'voided'])
            ->sum('pairs_produced');

        $targetPairs = (int) LineTarget::whereIn('line_id', $lineIds)
            ->whereBetween('target_date', [$startDate, $endDate])
            ->sum('target_pairs');

        $deliveryScore = $targetPairs > 0
            ? min(round(($actualPairs / $targetPairs) * 100, 2), 150.0)
            : 100.0; // no targets set → neutral score

        // ── Rejection rate ───────────────────────────────────────────────────
        $rejectedPairs = (int) QcRejection::whereIn('worker_id', $workerIds)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('status', ['applied', 'pending'])
            ->sum('pairs_rejected');

        $rejectionRate = $actualPairs > 0
            ? round($rejectedPairs / $actualPairs, 4)
            : 0.0;

        // ── Compliance score ─────────────────────────────────────────────────
        $registeredCount = Worker::whereIn('id', $workerIds)
            ->whereNotNull('eobi_number')
            ->whereNotNull('pessi_number')
            ->count();

        $complianceScore = round(($registeredCount / $totalWorkers) * 100, 2);

        // ── Min-wage shortfall count ─────────────────────────────────────────
        $minWageShortfallCount = 0;
        $payrollRun = WeeklyPayrollRun::where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->first();

        if ($payrollRun) {
            $minWageShortfallCount = (int) WorkerWeeklyPayroll::whereIn('worker_id', $workerIds)
                ->where('payroll_run_id', $payrollRun->id)
                ->where('min_wage_supplement', '>', 0)
                ->count();
        }

        // ── Ghost worker flags ───────────────────────────────────────────────
        $ghostFlags = (int) GhostWorkerFlag::whereIn('worker_id', $workerIds)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereNull('resolved_at')
            ->count();

        // ── Composite score ──────────────────────────────────────────────────
        // Quality = inverse of rejection: a 0% rejection rate = 100 quality score
        $qualityScore   = (1.0 - (float) $rejectionRate) * 100.0;
        $normalDelivery = min($deliveryScore, 100.0); // don't reward overproduction in composite
        $compositeScore = round(
            ($normalDelivery * self::WEIGHT_DELIVERY)
            + ($qualityScore  * self::WEIGHT_QUALITY)
            + ($complianceScore * self::WEIGHT_COMPLIANCE),
            2
        );

        $score = ContractorPerformanceScore::updateOrCreate(
            ['contractor_id' => $contractorId, 'week_ref' => $weekRef],
            [
                'delivery_score'           => $deliveryScore,
                'rejection_rate'           => $rejectionRate,
                'compliance_score'         => $complianceScore,
                'min_wage_shortfall_count' => $minWageShortfallCount,
                'ghost_worker_flags'       => $ghostFlags,
                'composite_score'          => $compositeScore,
            ]
        );

        return array_merge($score->fresh()->toArray(), [
            'detail' => [
                'total_workers'      => $totalWorkers,
                'registered_workers' => $registeredCount,
                'actual_pairs'       => $actualPairs,
                'target_pairs'       => $targetPairs,
                'rejected_pairs'     => $rejectedPairs,
                'quality_score'      => round($qualityScore, 2),
            ],
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Return [start_date, end_date] as date strings for a given ISO week ref.
     * Start = Monday, End = Saturday (6-day factory week).
     */
    private function weekBounds(string $weekRef): array
    {
        [$year, $isoWeek] = explode('-W', $weekRef);
        $monday = Carbon::now()
            ->setISODate((int) $year, (int) $isoWeek)
            ->startOfDay();

        return [
            $monday->toDateString(),
            $monday->copy()->addDays(5)->toDateString(),
        ];
    }
}
