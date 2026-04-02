<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\Deduction;
use App\Models\PayrollException;
use App\Models\WeeklyPayrollRun;
use App\Models\WorkerWeeklyPayroll;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdvanceService
{
    private float $autoApproveLimit;
    private int   $maxCarryWeeks;

    public function __construct()
    {
        $this->autoApproveLimit = (float) config('pieceworks.advance_auto_approve_limit', 2_000.00);
        $this->maxCarryWeeks    = (int)   config('pieceworks.advance_max_carry_weeks', 2);
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Determine auto-approval status for a new advance request.
     * Returns ['status', 'requires_approval'].
     */
    public function evaluateApproval(float $amount): array
    {
        $requiresApproval = $amount > $this->autoApproveLimit;
        return [
            'status'              => $requiresApproval ? 'pending' : 'approved',
            'requires_approval'   => $requiresApproval,
            'approved_at'         => $requiresApproval ? null : now(),
        ];
    }

    /**
     * Calculate the instalment to deduct this week for a given advance.
     *
     * Returns the PKR amount to deduct (may be less than instalment if remaining < instalment).
     */
    public function calculateInstalment(Advance $advance): float
    {
        $remaining  = (float) $advance->amount - (float) $advance->amount_deducted;
        if ($remaining <= 0) {
            return 0.0;
        }
        $instalment = round((float) $advance->amount / max(1, $advance->carry_weeks), 2);
        return min($instalment, $remaining);
    }

    /**
     * Standalone: fully process a single advance deduction against a payroll run.
     *
     * Used by API endpoint (not the batch payroll engine).
     * - Loads the worker's WorkerWeeklyPayroll for net earnings.
     * - Deducts what's available, carries rest.
     * - Creates a Deduction record.
     * - Flags HR if carried too long.
     *
     * Returns:
     * [
     *   deducted   => float,
     *   carried    => float,
     *   new_status => string,
     *   hr_flagged => bool,
     * ]
     */
    public function deductFromPayroll(int $advanceId, int $payrollRunId): array
    {
        return DB::transaction(function () use ($advanceId, $payrollRunId) {
            $advance    = Advance::lockForUpdate()->findOrFail($advanceId);
            $payrollRun = WeeklyPayrollRun::findOrFail($payrollRunId);

            if ($advance->status === 'fully_deducted') {
                return ['deducted' => 0.0, 'carried' => 0.0, 'new_status' => 'fully_deducted', 'hr_flagged' => false];
            }

            // Worker's net_pay this run BEFORE this advance is applied
            $wwp = WorkerWeeklyPayroll::where('payroll_run_id', $payrollRunId)
                ->where('worker_id', $advance->worker_id)
                ->first();

            $availableNet = $wwp ? (float) $wwp->net_pay : 0.0;
            $instalment   = $this->calculateInstalment($advance);
            $deducted     = min($instalment, $availableNet);
            $carried      = round($instalment - $deducted, 2);
            $newDeducted  = round((float) $advance->amount_deducted + $deducted, 2);

            // Update advance record
            $newStatus    = $newDeducted >= (float) $advance->amount
                ? 'fully_deducted'
                : ($newDeducted > 0 ? 'partially_deducted' : 'approved');

            $newCarried = $carried > 0
                ? $advance->carried_weeks + 1
                : $advance->carried_weeks;

            $advance->update([
                'amount_deducted' => $newDeducted,
                'carried_weeks'   => $newCarried,
                'status'          => $newStatus,
            ]);

            // Update WorkerWeeklyPayroll
            if ($wwp && $deducted > 0) {
                $wwp->update([
                    'advance_deductions'   => round((float) $wwp->advance_deductions + $deducted, 2),
                    'carry_forward_amount' => round((float) $wwp->carry_forward_amount + $carried, 2),
                    'net_pay'              => max(0, round((float) $wwp->net_pay - $deducted, 2)),
                ]);
            }

            // Create deduction ledger entry
            Deduction::create([
                'worker_id'        => $advance->worker_id,
                'payroll_run_id'   => $payrollRunId,
                'deduction_type_id'=> Deduction::typeId('advance_recovery'),
                'amount'           => $deducted,
                'reference_id'     => $advance->id,
                'reference_type'   => Advance::class,
                'week_ref'         => $payrollRun->week_ref,
                'carry_from_week'  => $carried > 0 ? $payrollRun->week_ref : null,
                'status'           => 'applied',
                'applied_at'       => now(),
            ]);

            // HR flag if carried too long
            $hrFlagged = false;
            if ($newCarried >= $this->maxCarryWeeks && $carried > 0) {
                $hrFlagged = true;
                PayrollException::create([
                    'payroll_run_id'           => $payrollRunId,
                    'worker_id'                => $advance->worker_id,
                    'worker_weekly_payroll_id' => $wwp?->id,
                    'exception_type'           => 'manual',
                    'description'              => sprintf(
                        'Advance #%d (PKR %.2f) has been carried forward for %d weeks. Remaining: PKR %.2f. HR review required.',
                        $advance->id,
                        (float) $advance->amount,
                        $newCarried,
                        round((float) $advance->amount - $newDeducted, 2)
                    ),
                    'amount' => $carried,
                ]);
            }

            return [
                'deducted'   => $deducted,
                'carried'    => $carried,
                'new_status' => $newStatus,
                'hr_flagged' => $hrFlagged,
            ];
        });
    }

    /**
     * Check if any of a worker's advances have been carried too long.
     * Used by the priority engine to surface alerts without deducting.
     */
    public function hasCarryAlert(int $workerId): bool
    {
        return Advance::where('worker_id', $workerId)
            ->whereIn('status', ['approved', 'partially_deducted'])
            ->where('carried_weeks', '>=', $this->maxCarryWeeks)
            ->exists();
    }

    // ── Helpers used by PayrollCalculationService ──────────────────────────

    /**
     * Load all advances eligible for deduction in this payroll week.
     * Returns collection ordered oldest-first.
     */
    public function pendingAdvances(int $workerId, string $weekRef): \Illuminate\Support\Collection
    {
        return Advance::where('worker_id', $workerId)
            ->where('deduction_week', '<=', $weekRef)
            ->whereIn('status', ['approved', 'partially_deducted'])
            ->orderBy('id')
            ->get();
    }
}
