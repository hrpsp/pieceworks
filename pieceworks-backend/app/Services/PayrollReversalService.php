<?php

namespace App\Services;

use App\Models\Deduction;
use App\Models\PayrollReversal;
use App\Models\ProductionRecord;
use App\Models\WeeklyPayrollRun;
use App\Models\WorkerWeeklyPayroll;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayrollReversalService
{
    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Reverse an entire paid payroll run.
     *
     * Flow:
     *   1. Validate status = paid.
     *   2. Mark all worker_weekly_payroll records as payment_status = reversed.
     *   3. Unlock production records for correction.
     *   4. Set run status = reversed.
     *   5. Create PayrollReversal record.
     *   6. Notify PayEdge (failure is logged but does not roll back the reversal).
     *   7. Write audit_logs entry.
     *
     * @throws \RuntimeException  If the run is not in 'paid' status.
     */
    public function reverseFullWeek(int $payrollRunId, string $reason, int $authorizedBy): PayrollReversal
    {
        $run = WeeklyPayrollRun::findOrFail($payrollRunId);

        if ($run->status !== 'paid') {
            throw new \RuntimeException(
                "Full reversal requires status = paid. Current status: '{$run->status}'."
            );
        }

        return DB::transaction(function () use ($run, $reason, $authorizedBy): PayrollReversal {

            // ── 1. Collect and offset all worker payroll records ────────────
            $wwps = WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
                ->lockForUpdate()
                ->get();

            $totalReversed = (float) $wwps->sum('net_pay');
            $workerCount   = $wwps->count();

            WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
                ->update(['payment_status' => 'reversed']);

            // ── 2. Unlock production records so corrections can be entered ──
            ProductionRecord::whereBetween('work_date', [
                $run->start_date->toDateString(),
                $run->end_date->toDateString(),
            ])->update(['is_locked' => false]);

            // ── 3. Mark run as reversed ─────────────────────────────────────
            $run->update(['status' => 'reversed']);

            // ── 4. Create reversal record ───────────────────────────────────
            $reversal = PayrollReversal::create([
                'payroll_run_id'        => $run->id,
                'reversal_type'         => 'full',
                'worker_id'             => null,
                'reason'                => $reason,
                'authorized_by'         => $authorizedBy,
                'reversed_workers'      => $workerCount,
                'total_amount_reversed' => $totalReversed,
                'payedge_notified'      => false,
            ]);

            // ── 5. Audit log ────────────────────────────────────────────────
            $this->writeAuditLog(
                $authorizedBy,
                'payroll_reversal_full',
                WeeklyPayrollRun::class,
                $run->id,
                ['status' => 'paid'],
                [
                    'status'              => 'reversed',
                    'reason'              => $reason,
                    'workers_affected'    => $workerCount,
                    'total_amount'        => $totalReversed,
                    'reversal_id'         => $reversal->id,
                ]
            );

            // ── 6. PayEdge notification (outside main critical path) ────────
            try {
                $this->notifyPayEdgeFullReversal($reversal, $run, $wwps);
            } catch (\Throwable $e) {
                Log::error('PayEdge reversal notification failed — reversal itself succeeded', [
                    'reversal_id' => $reversal->id,
                    'error'       => $e->getMessage(),
                ]);
            }

            return $reversal->fresh(['authorizedBy:id,name']);
        });
    }

    /**
     * Reverse a single worker's payment within a paid (or locked) run.
     *
     * Flow:
     *   1. Mark the worker's WWP record as reversed.
     *   2. Create a carry-forward Deduction in the next available payroll run
     *      so the over-payment is recovered automatically.
     *   3. Create a PayrollReversal record.
     *   4. Write audit log.
     *
     * The run itself stays 'paid'; only the individual worker's line is reversed.
     *
     * @throws \RuntimeException  If the worker has no WWP record in the given run.
     */
    public function reverseWorkerPayment(
        int    $workerId,
        int    $payrollRunId,
        string $reason,
        int    $authorizedBy,
    ): PayrollReversal {
        $run = WeeklyPayrollRun::findOrFail($payrollRunId);

        if (! in_array($run->status, ['paid', 'locked'])) {
            throw new \RuntimeException(
                "Worker payment reversal requires run status paid or locked. Current: '{$run->status}'."
            );
        }

        $wwp = WorkerWeeklyPayroll::where('payroll_run_id', $payrollRunId)
            ->where('worker_id', $workerId)
            ->firstOrFail();

        return DB::transaction(function () use ($run, $wwp, $workerId, $payrollRunId, $reason, $authorizedBy): PayrollReversal {

            $netPay = (float) $wwp->net_pay;

            // ── 1. Mark this worker's record reversed ───────────────────────
            $wwp->update(['payment_status' => 'reversed']);

            // ── 2. Carry over-payment as deduction in next week ─────────────
            if ($netPay > 0) {
                $nextRun = WeeklyPayrollRun::where('start_date', '>', $run->end_date)
                    ->whereIn('status', ['open', 'processing'])
                    ->orderBy('start_date')
                    ->first();

                try {
                    Deduction::create([
                        'worker_id'         => $workerId,
                        'payroll_run_id'     => $nextRun?->id,
                        'deduction_type_id'  => Deduction::typeId('reversal_recovery'),
                        'amount'             => $netPay,
                        'reference_id'       => $wwp->id,
                        'reference_type'     => WorkerWeeklyPayroll::class,
                        'week_ref'           => $nextRun?->week_ref ?? 'next_available',
                        'carry_from_week'    => $run->week_ref,
                        'status'             => 'pending',
                    ]);
                } catch (\RuntimeException $e) {
                    // 'reversal_recovery' deduction type not yet seeded; log and continue.
                    Log::warning('Could not create reversal carry-forward Deduction: ' . $e->getMessage(), [
                        'worker_id'      => $workerId,
                        'payroll_run_id' => $payrollRunId,
                        'amount'         => $netPay,
                    ]);
                }
            }

            // ── 3. Create reversal record ───────────────────────────────────
            $reversal = PayrollReversal::create([
                'payroll_run_id'        => $payrollRunId,
                'reversal_type'         => 'partial',
                'worker_id'             => $workerId,
                'reason'                => $reason,
                'authorized_by'         => $authorizedBy,
                'reversed_workers'      => 1,
                'total_amount_reversed' => $netPay,
                'payedge_notified'      => false,
            ]);

            // ── 4. Audit log ────────────────────────────────────────────────
            $this->writeAuditLog(
                $authorizedBy,
                'payroll_reversal_partial',
                WorkerWeeklyPayroll::class,
                $wwp->id,
                ['payment_status' => 'paid', 'net_pay' => $netPay],
                [
                    'payment_status'  => 'reversed',
                    'reason'          => $reason,
                    'carry_forward'   => $netPay,
                    'reversal_id'     => $reversal->id,
                ]
            );

            return $reversal->fresh(['authorizedBy:id,name', 'worker:id,name']);
        });
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Notify PayEdge of a full-week reversal.
     * Updates the PayrollReversal record with the notification result.
     */
    private function notifyPayEdgeFullReversal(
        PayrollReversal $reversal,
        WeeklyPayrollRun $run,
        Collection $wwps,
    ): void {
        $endpoint = config('services.payedge.endpoint');
        $apiKey   = config('services.payedge.api_key');

        if (empty($endpoint)) {
            Log::info('PayEdge reversal notification skipped — PAYEDGE_API_ENDPOINT not configured.');
            return;
        }

        $payload = [
            'reversal_type'   => 'full_week',
            'payroll_period'  => $run->week_ref,
            'reason'          => $reversal->reason,
            'reversed_at'     => now()->toIso8601String(),
            'source'          => 'pieceworks',
            'workers'         => $wwps->map(fn ($w) => [
                'worker_id' => $w->worker_id,
                'net_pay'   => (float) $w->net_pay,
            ])->values()->all(),
        ];

        $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
            ->timeout((int) config('services.payedge.timeout', 30))
            ->acceptJson()
            ->post(rtrim($endpoint, '/') . '/reversals', $payload);

        $reversal->update([
            'payedge_notified'    => $response->successful(),
            'payedge_notified_at' => now(),
            'payedge_response'    => $response->json() ?? ['raw' => mb_substr($response->body(), 0, 500)],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "PayEdge HTTP {$response->status()}: " . mb_substr($response->body(), 0, 300)
            );
        }
    }

    /** Write a row to audit_logs using raw DB insert (no model needed). */
    private function writeAuditLog(
        int    $userId,
        string $action,
        string $modelType,
        int    $modelId,
        array  $oldValues,
        array  $newValues,
    ): void {
        DB::table('audit_logs')->insert([
            'user_id'    => $userId,
            'action'     => $action,
            'model_type' => $modelType,
            'model_id'   => $modelId,
            'old_values' => json_encode($oldValues),
            'new_values' => json_encode($newValues),
            'ip_address' => request()->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
