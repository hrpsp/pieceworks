<?php

namespace App\Services;

use App\Models\Deduction;
use App\Models\Loan;
use App\Models\WeeklyPayrollRun;
use App\Models\WorkerWeeklyPayroll;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanService
{
    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Process all EMI deductions for a worker in a payroll run.
     *
     * Iterates active loans oldest-first.
     * For each loan, deducts min(emi, balance, available_net).
     * Marks loans fully_paid when balance reaches zero.
     * Creates Deduction ledger entries.
     *
     * Returns:
     * [
     *   total_deducted => float,
     *   loans_processed => [['loan_id', 'emi', 'deducted', 'new_balance', 'status'], ...]
     * ]
     */
    public function processWeeklyEMI(int $workerId, int $payrollRunId): array
    {
        return DB::transaction(function () use ($workerId, $payrollRunId) {
            $payrollRun = WeeklyPayrollRun::findOrFail($payrollRunId);

            $wwp = WorkerWeeklyPayroll::where('payroll_run_id', $payrollRunId)
                ->where('worker_id', $workerId)
                ->first();

            $availableNet  = $wwp ? (float) $wwp->net_pay : 0.0;
            $totalDeducted = 0.0;
            $processed     = [];

            $loans = Loan::where('worker_id', $workerId)
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($loans as $loan) {
                if ($availableNet <= 0) {
                    break;
                }

                $emi      = min((float) $loan->weekly_emi, (float) $loan->outstanding_balance);
                $deducted = min($emi, $availableNet);
                $available = round($availableNet - $deducted, 2);
                $newBalance = round((float) $loan->outstanding_balance - $deducted, 2);
                $newStatus  = $newBalance <= 0 ? 'fully_paid' : 'active';

                $loan->update([
                    'outstanding_balance' => $newBalance,
                    'status'              => $newStatus,
                ]);

                Deduction::create([
                    'worker_id'        => $workerId,
                    'payroll_run_id'   => $payrollRunId,
                    'deduction_type_id'=> Deduction::typeId('loan_emi'),
                    'amount'           => $deducted,
                    'reference_id'     => $loan->id,
                    'reference_type'   => Loan::class,
                    'week_ref'         => $payrollRun->week_ref,
                    'status'           => 'applied',
                    'applied_at'       => now(),
                ]);

                $totalDeducted  += $deducted;
                $availableNet    = $available;
                $processed[]     = [
                    'loan_id'     => $loan->id,
                    'emi'         => $emi,
                    'deducted'    => $deducted,
                    'new_balance' => $newBalance,
                    'status'      => $newStatus,
                ];
            }

            // Update WorkerWeeklyPayroll loan line
            if ($wwp && $totalDeducted > 0) {
                $wwp->update([
                    'loan_deductions' => round((float) $wwp->loan_deductions + $totalDeducted, 2),
                    'net_pay'         => max(0, round((float) $wwp->net_pay - $totalDeducted, 2)),
                ]);
            }

            return [
                'total_deducted' => round($totalDeducted, 2),
                'loans_processed'=> $processed,
            ];
        });
    }

    /**
     * Early settlement: immediately zero out a loan if settle_amount >= outstanding.
     * Partial settlement reduces outstanding_balance only.
     */
    public function earlySettle(Loan $loan, float $settleAmount): array
    {
        $outstanding = (float) $loan->outstanding_balance;

        if ($settleAmount >= $outstanding) {
            $loan->update([
                'outstanding_balance' => 0,
                'status'              => 'fully_paid',
            ]);
            return [
                'settled'         => $outstanding,
                'new_balance'     => 0.0,
                'status'          => 'fully_paid',
                'fully_settled'   => true,
            ];
        }

        $newBalance = round($outstanding - $settleAmount, 2);
        $loan->update(['outstanding_balance' => $newBalance]);

        return [
            'settled'       => $settleAmount,
            'new_balance'   => $newBalance,
            'status'        => 'active',
            'fully_settled' => false,
        ];
    }

    /**
     * Generate a week-by-week repayment schedule for display.
     * Starts from today's ISO week.
     */
    public function getRepaymentSchedule(Loan $loan): array
    {
        $balance    = (float) $loan->outstanding_balance;
        $emi        = (float) $loan->weekly_emi;
        $schedule   = [];
        $current    = Carbon::now();
        $weekNum    = 1;

        while ($balance > 0 && $weekNum <= 260) { // safety cap at 5 years
            $payment    = min($emi, $balance);
            $balance    = round($balance - $payment, 2);
            $weekRef    = $current->isoWeekYear() . '-W' . str_pad((string) $current->isoWeek(), 2, '0', STR_PAD_LEFT);

            $schedule[] = [
                'week'        => $weekNum,
                'week_ref'    => $weekRef,
                'payment'     => $payment,
                'balance'     => $balance,
            ];

            $current->addWeek();
            $weekNum++;
        }

        return $schedule;
    }
}
