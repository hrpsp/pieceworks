<?php

namespace App\Services;

use App\Models\PayEdgeHandoffLog;
use App\Models\WeeklyPayrollRun;
use App\Models\WorkerWeeklyPayroll;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayEdgeHandoffService
{
    // Maximum delivery attempts per worker before marking as failed.
    private const MAX_ATTEMPTS = 3;

    // Milliseconds to wait between retry attempts (increases per attempt).
    private const RETRY_DELAYS_MS = [0, 200, 400]; // attempt 1 = immediate, 2 = 200ms, 3 = 400ms

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * POST each worker's payroll record to the PayEdge API.
     *
     * Idempotent: already-sent records are skipped unless $force = true.
     * Retries each failed record up to MAX_ATTEMPTS times with short back-off.
     *
     * Returns a summary array: { total, sent, failed, skipped }.
     *
     * @throws \RuntimeException  If the run is not locked or paid, or PayEdge is not configured.
     */
    public function sendWeeklyHandoff(int $payrollRunId, bool $force = false): array
    {
        $run = WeeklyPayrollRun::findOrFail($payrollRunId);

        if (! in_array($run->status, ['locked', 'paid'])) {
            throw new \RuntimeException(
                "PayEdge handoff requires run status locked or paid. Current: '{$run->status}'."
            );
        }

        $endpoint = config('services.payedge.endpoint');
        $apiKey   = config('services.payedge.api_key');

        if (empty($endpoint)) {
            throw new \RuntimeException(
                'PayEdge endpoint is not configured. Set PAYEDGE_API_ENDPOINT in .env.'
            );
        }

        $wwps = WorkerWeeklyPayroll::where('payroll_run_id', $payrollRunId)
            ->with('worker:id,contractor_id,payment_number')
            ->get();

        $sent    = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ($wwps as $wwp) {
            // ── Idempotency guard ────────────────────────────────────────────
            $log = PayEdgeHandoffLog::firstOrNew(
                ['payroll_run_id' => $payrollRunId, 'worker_id' => $wwp->worker_id],
                ['week_ref' => $run->week_ref, 'status' => 'pending', 'attempts' => 0]
            );

            if (! $log->exists) {
                $log->save();
            }

            if (! $force && $log->status === 'sent') {
                $skipped++;
                continue;
            }

            // ── Build payload ────────────────────────────────────────────────
            $payload = [
                'worker_id'               => $wwp->worker_id,
                'payroll_period'          => $run->week_ref,
                'gross_piece_earnings'    => (float) $wwp->gross_earnings,
                'minimum_wage_supplement' => (float) $wwp->min_wage_supplement,
                'overtime_premium'        => (float) $wwp->ot_premium,
                'holiday_pay'             => (float) $wwp->holiday_pay,
                'shift_allowance'         => (float) $wwp->shift_allowance,
                'total_gross'             => (float) $wwp->total_gross,
                'advance_deductions'      => (float) $wwp->advance_deductions,
                'rejection_deductions'    => (float) $wwp->rejection_deductions,
                'loan_deductions'         => (float) $wwp->loan_deductions,
                'net_pay'                 => (float) $wwp->net_pay,
                'payment_released'        => $run->status === 'paid',
                'payment_method'          => $wwp->payment_method,
                'contractor_id'           => $wwp->contractor_id,
                'source'                  => 'pieceworks',
            ];

            // ── Attempt delivery with retries ────────────────────────────────
            $success   = false;
            $lastError = null;

            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                $delayMs = self::RETRY_DELAYS_MS[$attempt - 1] ?? 400;
                if ($delayMs > 0) {
                    usleep($delayMs * 1_000);
                }

                $log->update([
                    'status'            => $attempt === 1 ? 'pending' : 'retrying',
                    'attempts'          => $attempt,
                    'last_attempted_at' => now(),
                    'payload'           => $payload,
                ]);

                try {
                    $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
                        ->timeout((int) config('services.payedge.timeout', 30))
                        ->acceptJson()
                        ->post(rtrim($endpoint, '/') . '/payroll-records', $payload);

                    if ($response->successful()) {
                        $log->update([
                            'status'    => 'sent',
                            'response'  => $response->json(),
                            'sent_at'   => $log->sent_at ?? now(), // preserve first sent_at on re-send
                            'error_message' => null,
                        ]);
                        $success = true;
                        $sent++;
                        break;
                    }

                    $lastError = "HTTP {$response->status()}: " . mb_substr($response->body(), 0, 300);

                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                }
            }

            if (! $success) {
                $log->update([
                    'status'        => 'failed',
                    'error_message' => $lastError,
                ]);
                $failed++;
                Log::warning('PayEdge handoff failed after ' . self::MAX_ATTEMPTS . ' attempts', [
                    'worker_id'      => $wwp->worker_id,
                    'payroll_run_id' => $payrollRunId,
                    'error'          => $lastError,
                ]);
            }
        }

        return [
            'total'   => $wwps->count(),
            'sent'    => $sent,
            'failed'  => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Re-attempt delivery for all workers in a run that are currently in 'failed' status.
     * Useful for recovering from transient PayEdge outages without re-sending successes.
     */
    public function retryFailed(int $payrollRunId): array
    {
        $failedWorkerIds = PayEdgeHandoffLog::where('payroll_run_id', $payrollRunId)
            ->where('status', 'failed')
            ->pluck('worker_id');

        if ($failedWorkerIds->isEmpty()) {
            return ['retried' => 0, 'message' => 'No failed records to retry.'];
        }

        // Reset failed records to pending so sendWeeklyHandoff re-processes them
        PayEdgeHandoffLog::where('payroll_run_id', $payrollRunId)
            ->where('status', 'failed')
            ->update(['status' => 'pending', 'attempts' => 0]);

        $result = $this->sendWeeklyHandoff($payrollRunId, force: true);

        return array_merge($result, ['retried' => $failedWorkerIds->count()]);
    }
}
