<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\Deduction;
use App\Models\Loan;
use App\Models\PayrollException;
use App\Models\ProductionRecord;
use App\Models\QcRejection;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use App\Models\WorkerWeeklyPayroll;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollCalculationService
{
    private const DEFAULT_MIN_WEEKLY_WAGE = 8_545.00;
    private const DEFAULT_SHIFT_ALLOWANCE = 500.00;

    public function __construct(
        private AdvanceService    $advanceService,
        private ComplianceService $complianceService
    ) {}

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Calculate (or recalculate) payroll for an ISO week reference (e.g. '2025-W12').
     *
     * Idempotent: calling again on an open/processing run clears previous results
     * and rebuilds from current production data.
     *
     * @throws \RuntimeException  When run is locked or paid (immutable).
     */
    public function calculateWeek(string $weekRef): WeeklyPayrollRun
    {
        [$startDate, $endDate] = $this->weekBounds($weekRef);

        return DB::transaction(function () use ($weekRef, $startDate, $endDate) {
            $run = WeeklyPayrollRun::firstOrCreate(
                ['week_ref' => $weekRef],
                [
                    'start_date' => $startDate,
                    'end_date'   => $endDate,
                    'status'     => 'open',
                ]
            );

            if (in_array($run->status, ['locked', 'paid'])) {
                throw new \RuntimeException("Payroll run {$weekRef} is {$run->status} and cannot be recalculated.");
            }

            $run->update(['status' => 'processing']);
            $run->workerPayrolls()->delete();
            $run->exceptions()->delete();

            $workerIds = ProductionRecord::whereBetween('work_date', [$startDate, $endDate])
                ->whereNotIn('validation_status', ['rejected'])
                ->pluck('worker_id')
                ->unique()
                ->values();

            foreach ($workerIds as $workerId) {
                $this->processWorker($run, $workerId, $startDate, $endDate, $weekRef);
            }

            // Roll up run-level totals
            $payrolls = $run->workerPayrolls()->get();

            $run->update([
                'total_gross'      => $payrolls->sum('gross_earnings'),
                'total_topups'     => $payrolls->sum('ot_premium')
                                   + $payrolls->sum('shift_allowance')
                                   + $payrolls->sum('holiday_pay')
                                   + $payrolls->sum('min_wage_supplement'),
                'total_deductions' => $payrolls->sum('advance_deductions')
                                   + $payrolls->sum('rejection_deductions')
                                   + $payrolls->sum('loan_deductions')
                                   + $payrolls->sum('other_deductions'),
                'total_net'        => $payrolls->sum('net_pay'),
                'status'           => 'open',
            ]);

            return $run->fresh();
        });
    }

    // ── Private: per-worker calculation ────────────────────────────────────

    private function processWorker(
        WeeklyPayrollRun $run,
        int $workerId,
        Carbon $startDate,
        Carbon $endDate,
        string $weekRef
    ): void {
        $worker = Worker::find($workerId);
        if (! $worker) {
            return;
        }

        // ── 1. Production earnings ──────────────────────────────────────────
        $records = ProductionRecord::where('worker_id', $workerId)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereNotIn('validation_status', ['rejected'])
            ->get();

        $grossEarnings  = (float) $records->sum('gross_earnings');
        $otPremium      = (float) $records->where('shift_adjustment', '>', 0)->sum('shift_adjustment');
        $shiftPenalties = (float) abs($records->where('shift_adjustment', '<', 0)->sum('shift_adjustment'));

        // ── 2. Shift allowance ──────────────────────────────────────────────
        $shiftAllowance = (float) config('pieceworks.shift_allowance_per_worker', self::DEFAULT_SHIFT_ALLOWANCE);

        // ── 3. Holiday pay — check every day in the work week ───────────────
        $province   = config('pieceworks.default_province', 'punjab');
        $holidayPay = 0.0;
        $cursor     = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $holidayPay += $this->complianceService->calculateHolidayPay($workerId, $cursor, $province);
            $cursor->addDay();
        }

        $totalBeforeFloor = $grossEarnings + $otPremium + $shiftAllowance + $holidayPay;

        // ── 4. Province-aware minimum wage floor ────────────────────────────
        $wageCheck         = $this->complianceService->checkMinimumWage($workerId, $totalBeforeFloor, $province);
        $minWageSupplement = $wageCheck['topup_amount'];

        $totalGross = $totalBeforeFloor + $minWageSupplement;
        $available  = $totalGross; // running budget — never allowed to go below 0

        // ════════════════════════════════════════════════════════════════════
        // DEDUCTION PRIORITY ENGINE
        // ════════════════════════════════════════════════════════════════════

        // ── P1: Rejection carry-forwards (locked-week overflows, oldest first) ──
        $rejectionCarryApplied = 0.0;
        $carryDedResults       = [];

        $carryDeds = Deduction::where('worker_id', $workerId)
            ->whereNotNull('carry_from_week')
            ->where('reference_type', QcRejection::class)
            ->where('status', 'pending')
            ->orderBy('carry_from_week')
            ->lockForUpdate()
            ->get();

        foreach ($carryDeds as $ded) {
            if ($available <= 0) {
                break;
            }
            $requested  = (float) $ded->amount;
            $take       = min($requested, $available);
            $available  = round($available - $take, 2);
            $rejectionCarryApplied += $take;
            $carryDedResults[] = [
                'ded'       => $ded,
                'taken'     => $take,
                'remainder' => round($requested - $take, 2),
            ];
        }

        // ── P2: This-week QC rejection penalties ────────────────────────────
        $thisWeekRejectionTotal = (float) QcRejection::where('worker_id', $workerId)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('status', ['pending', 'applied'])
            ->sum('penalty_amount');
        $thisWeekRejectionTotal   += $shiftPenalties;
        $thisWeekRejectionApplied  = min($thisWeekRejectionTotal, $available);
        $available                 = round($available - $thisWeekRejectionApplied, 2);

        $rejectionDeductions = round($rejectionCarryApplied + $thisWeekRejectionApplied, 2);

        // ── P3: Loan EMI installments (oldest loan first) ───────────────────
        $loanDeductions = 0.0;
        $loanUpdates    = [];

        $loans = Loan::where('worker_id', $workerId)
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($loans as $loan) {
            if ($available <= 0) {
                break;
            }
            $emi        = min((float) $loan->weekly_emi, (float) $loan->outstanding_balance);
            $take       = min($emi, $available);
            $newBalance = round((float) $loan->outstanding_balance - $take, 2);

            $loanUpdates[] = [
                'loan'        => $loan,
                'deducted'    => $take,
                'new_balance' => $newBalance,
                'status'      => $newBalance <= 0 ? 'fully_paid' : 'active',
            ];
            $loanDeductions += $take;
            $available       = round($available - $take, 2);
        }

        // ── P4: Advance recovery (approved/partially_deducted, due this week) ──
        $advanceDeductions = 0.0;
        $advanceCarried    = 0.0;
        $advanceUpdates    = [];

        $advances = $this->advanceService->pendingAdvances($workerId, $weekRef);

        foreach ($advances as $advance) {
            $instalment = $this->advanceService->calculateInstalment($advance);
            if ($instalment <= 0) {
                continue;
            }
            $take        = min($instalment, max(0.0, $available));
            $carried     = round($instalment - $take, 2);
            $newDeducted = round((float) $advance->amount_deducted + $take, 2);
            $newCarried  = $carried > 0 ? $advance->carried_weeks + 1 : $advance->carried_weeks;
            $newStatus   = $newDeducted >= (float) $advance->amount
                ? 'fully_deducted'
                : ($newDeducted > 0 ? 'partially_deducted' : 'approved');

            $advanceUpdates[] = [
                'advance'      => $advance,
                'deducted'     => $take,
                'new_deducted' => $newDeducted,
                'carried'      => $carried,
                'new_carried'  => $newCarried,
                'new_status'   => $newStatus,
            ];
            $advanceDeductions += $take;
            $advanceCarried    += $carried;
            if ($take > 0) {
                $available = round($available - $take, 2);
            }
        }

        // ── P5: Material / equipment / miscellaneous deductions ─────────────
        $otherDeductions = 0.0;
        $otherDedResults = [];

        $pendingOther = Deduction::where('worker_id', $workerId)
            ->where('week_ref', $weekRef)
            ->whereNull('carry_from_week')
            ->where('status', 'pending')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($pendingOther as $ded) {
            if ($available <= 0) {
                break;
            }
            $take              = min((float) $ded->amount, $available);
            $otherDedResults[] = ['ded' => $ded, 'taken' => $take];
            $otherDeductions  += $take;
            $available         = round($available - $take, 2);
        }

        // ── Net pay (never negative by construction) ─────────────────────────
        $netPay             = $available;
        $carryForwardAmount = $advanceCarried;

        // ════════════════════════════════════════════════════════════════════
        // PERSIST WorkerWeeklyPayroll
        // ════════════════════════════════════════════════════════════════════
        $wwp = WorkerWeeklyPayroll::create([
            'payroll_run_id'       => $run->id,
            'worker_id'            => $workerId,
            'contractor_id'        => $worker->contractor_id,
            'gross_earnings'       => round($grossEarnings, 2),
            'ot_premium'           => round($otPremium, 2),
            'shift_allowance'      => round($shiftAllowance, 2),
            'holiday_pay'          => round($holidayPay, 2),
            'min_wage_supplement'  => round($minWageSupplement, 2),
            'total_gross'          => round($totalGross, 2),
            'advance_deductions'   => round($advanceDeductions, 2),
            'rejection_deductions' => round($rejectionDeductions, 2),
            'loan_deductions'      => round($loanDeductions, 2),
            'other_deductions'     => round($otherDeductions, 2),
            'carry_forward_amount' => round($carryForwardAmount, 2),
            'net_pay'              => round($netPay, 2),
            'payment_method'       => $worker->payment_method ?? 'cash',
        ]);

        // ════════════════════════════════════════════════════════════════════
        // SIDE-EFFECTS: commit state changes and ledger entries
        // ════════════════════════════════════════════════════════════════════

        // Mark this-week QC rejections applied
        QcRejection::where('worker_id', $workerId)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->where('status', 'pending')
            ->update(['status' => 'applied']);

        // Apply/roll carry-forward rejection deductions
        foreach ($carryDedResults as $cfd) {
            if ($cfd['remainder'] <= 0) {
                $cfd['ded']->updateQuietly([
                    'status'         => 'applied',
                    'payroll_run_id' => $run->id,
                    'applied_at'     => now(),
                ]);
            } else {
                // Partially covered — mark original for taken amount, spawn new pending carry
                $cfd['ded']->updateQuietly([
                    'amount'         => $cfd['taken'],
                    'status'         => 'applied',
                    'payroll_run_id' => $run->id,
                    'applied_at'     => now(),
                ]);
                Deduction::create([
                    'worker_id'         => $workerId,
                    'deduction_type_id' => $cfd['ded']->deduction_type_id,
                    'amount'            => $cfd['remainder'],
                    'reference_id'      => $cfd['ded']->reference_id,
                    'reference_type'    => $cfd['ded']->reference_type,
                    'week_ref'          => $weekRef,
                    'carry_from_week'   => $cfd['ded']->carry_from_week,
                    'status'            => 'pending',
                ]);
            }
        }

        // Commit loan updates + Deduction ledger entries
        foreach ($loanUpdates as $u) {
            $u['loan']->update([
                'outstanding_balance' => $u['new_balance'],
                'status'              => $u['status'],
            ]);
            if ($u['deducted'] > 0) {
                Deduction::create([
                    'worker_id'         => $workerId,
                    'payroll_run_id'    => $run->id,
                    'deduction_type_id' => Deduction::typeId('loan_emi'),
                    'amount'            => $u['deducted'],
                    'reference_id'      => $u['loan']->id,
                    'reference_type'    => Loan::class,
                    'week_ref'          => $weekRef,
                    'status'            => 'applied',
                    'applied_at'        => now(),
                ]);
            }
        }

        // Commit advance updates + Deduction ledger entries
        foreach ($advanceUpdates as $u) {
            $u['advance']->update([
                'amount_deducted' => $u['new_deducted'],
                'carried_weeks'   => $u['new_carried'],
                'status'          => $u['new_status'],
            ]);
            if ($u['deducted'] > 0) {
                Deduction::create([
                    'worker_id'         => $workerId,
                    'payroll_run_id'    => $run->id,
                    'deduction_type_id' => Deduction::typeId('advance_recovery'),
                    'amount'            => $u['deducted'],
                    'reference_id'      => $u['advance']->id,
                    'reference_type'    => Advance::class,
                    'week_ref'          => $weekRef,
                    'status'            => 'applied',
                    'applied_at'        => now(),
                ]);
            }
        }

        // Mark other pending deductions applied
        foreach ($otherDedResults as $u) {
            $u['ded']->updateQuietly([
                'status'         => 'applied',
                'payroll_run_id' => $run->id,
                'applied_at'     => now(),
            ]);
        }

        // ── Flag payroll + compliance exceptions ────────────────────────────
        $this->flagExceptions(
            $run,
            $wwp,
            $records,
            $minWageSupplement,
            $carryForwardAmount,
            $advanceUpdates,
            $worker,
            $totalGross
        );
    }

    // ── Private: exception detection ───────────────────────────────────────

    private function flagExceptions(
        WeeklyPayrollRun $run,
        WorkerWeeklyPayroll $wwp,
        \Illuminate\Support\Collection $records,
        float $minWageSupplement,
        float $carryForwardAmount,
        array $advanceUpdates,
        Worker $worker,
        float $totalGross
    ): void {
        $workerId = $wwp->worker_id;
        $base     = [
            'payroll_run_id'           => $run->id,
            'worker_id'                => $workerId,
            'worker_weekly_payroll_id' => $wwp->id,
        ];

        // ── 1. Minimum wage shortfall ───────────────────────────────────────
        if ($minWageSupplement > 0) {
            PayrollException::create($base + [
                'exception_type' => 'min_wage_shortfall',
                'description'    => "Piece-rate earnings fell below minimum weekly wage. Top-up applied: PKR {$minWageSupplement}.",
                'amount'         => $minWageSupplement,
            ]);
        }

        // ── 2. Production records with no rate resolved ─────────────────────
        $missingRateCount = $records->whereNull('rate_card_entry_id')->count();
        if ($missingRateCount > 0) {
            PayrollException::create($base + [
                'exception_type' => 'missing_rate',
                'description'    => "{$missingRateCount} production record(s) have no rate card entry — earnings may be understated.",
                'amount'         => null,
            ]);
        }

        // ── 3. Carry-forward alert (advance instalments > 3× 3-week avg) ───
        if ($carryForwardAmount > 0) {
            $alertMultiplier = (float) config('pieceworks.carry_alert_multiplier', 3);
            $recentAvgGross  = (float) (WorkerWeeklyPayroll::where('worker_id', $workerId)
                ->where('payroll_run_id', '!=', $run->id)
                ->orderByDesc('id')
                ->limit(3)
                ->avg('total_gross') ?? 0.0);

            if ($recentAvgGross > 0 && $carryForwardAmount > $alertMultiplier * $recentAvgGross) {
                PayrollException::create($base + [
                    'exception_type' => 'negative_net_carry',
                    'description'    => sprintf(
                        'Uncollected advance instalments (PKR %.2f) exceed %.0f× 3-week avg gross (PKR %.2f). HR review required.',
                        $carryForwardAmount,
                        $alertMultiplier,
                        $recentAvgGross
                    ),
                    'amount' => $carryForwardAmount,
                ]);
            }
        }

        // ── 4. Per-advance HR alert when carried too long ───────────────────
        $maxCarryWeeks = (int) config('pieceworks.advance_max_carry_weeks', 2);
        foreach ($advanceUpdates as $u) {
            if ($u['carried'] > 0 && $u['new_carried'] >= $maxCarryWeeks) {
                PayrollException::create($base + [
                    'exception_type' => 'manual',
                    'description'    => sprintf(
                        'Advance #%d (PKR %.2f) has been carried forward for %d week(s). Remaining: PKR %.2f. HR review required.',
                        $u['advance']->id,
                        (float) $u['advance']->amount,
                        $u['new_carried'],
                        round((float) $u['advance']->amount - $u['new_deducted'], 2)
                    ),
                    'amount' => $u['carried'],
                ]);
            }
        }

        // ── 5. Disputed production records included ─────────────────────────
        $disputedCount = $records->where('validation_status', 'disputed')->count();
        if ($disputedCount > 0) {
            PayrollException::create($base + [
                'exception_type' => 'disputed_records',
                'description'    => "{$disputedCount} production record(s) in 'disputed' status were included in earnings. Review before locking.",
                'amount'         => null,
            ]);
        }

        // ── 6. WHT alert — only if no unresolved WHT exception exists ───────
        $annualProjection = $this->complianceService->projectAnnualEarnings($workerId);
        if ($annualProjection['wht_applicable']) {
            $existingWht = PayrollException::where('worker_id', $workerId)
                ->where('exception_type', 'wht_alert')
                ->whereNull('resolved_at')
                ->exists();

            if (! $existingWht) {
                PayrollException::create($base + [
                    'exception_type' => 'wht_alert',
                    'description'    => sprintf(
                        'Projected annual earnings PKR %.2f exceed WHT threshold PKR %.2f. Income tax registration required.',
                        $annualProjection['projected_annual'],
                        $annualProjection['taxable_threshold']
                    ),
                    'amount' => $annualProjection['projected_annual'],
                ]);
            }
        }

        // ── 7. Tenure milestones ────────────────────────────────────────────
        $joinDate    = $worker->join_date instanceof \Carbon\Carbon
            ? $worker->join_date
            : ($worker->join_date ? \Carbon\Carbon::parse($worker->join_date) : null);
        $unalerted = $this->complianceService->checkTenureMilestones($workerId, $joinDate);

        foreach ($unalerted as $milestone) {
            PayrollException::create($base + [
                'exception_type' => 'tenure_milestone',
                'description'    => sprintf(
                    'Worker reached %s (%d days) on %s. Review employment terms and statutory obligations.',
                    $milestone['label'],
                    (int) $milestone['milestone_days'],
                    $milestone['reached_at']
                ),
                'amount' => null,
            ]);
        }

        if (! empty($unalerted)) {
            $this->complianceService->markMilestonesAlerted($workerId);
        }

        // ── 8. Compliance gap — missing EOBI / PESSI registration ──────────
        if (is_null($worker->eobi_number) || is_null($worker->pessi_number)) {
            $existing = PayrollException::where('worker_id', $workerId)
                ->where('exception_type', 'compliance_gap')
                ->whereNull('resolved_at')
                ->exists();

            if (! $existing) {
                $missing = collect([
                    is_null($worker->eobi_number)  ? 'EOBI'  : null,
                    is_null($worker->pessi_number) ? 'PESSI' : null,
                ])->filter()->implode(', ');

                PayrollException::create($base + [
                    'exception_type' => 'compliance_gap',
                    'description'    => "Worker has no {$missing} registration number on file. Register via /api/compliance/register-eobi.",
                    'amount'         => null,
                ]);
            }
        }
    }

    // ── Private: helpers ────────────────────────────────────────────────────

    /**
     * Parse an ISO week ref (e.g. '2025-W12') into [Monday, Saturday] Carbon dates.
     */
    private function weekBounds(string $weekRef): array
    {
        [$year, $isoWeek] = explode('-W', $weekRef);

        $monday   = Carbon::now()->setISODate((int) $year, (int) $isoWeek)->startOfDay();
        $saturday = $monday->copy()->addDays(5);

        return [$monday, $saturday];
    }
}
