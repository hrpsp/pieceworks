<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWorkerStatementJob;
use App\Models\PaymentFile;
use App\Models\PayrollException;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use App\Models\WorkerStatement;
use App\Models\WorkerWeeklyPayroll;
use App\Services\PaymentFileService;
use App\Services\WorkerStatementService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PayrollStatementController extends Controller
{
    public function __construct(
        private WorkerStatementService $statementService,
        private PaymentFileService     $paymentFileService,
    ) {}

    // ── POST /api/payroll/{weekRef}/generate-statements ──────────────────────

    /**
     * Queue statement generation for every worker in a locked run.
     */
    public function generateStatements(string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        if (! in_array($run->status, ['locked', 'paid'])) {
            return $this->error(
                "Statements can only be generated for locked or paid runs (current: {$run->status}).",
                409
            );
        }

        $queued = $this->statementService->generateAllStatements($run->id);

        return $this->success([
            'queued'  => $queued,
            'message' => "{$queued} statement generation job(s) dispatched to the 'statements' queue.",
        ]);
    }

    // ── GET /api/workers/{id}/statement/{weekRef} ────────────────────────────

    /**
     * Return the compiled statement JSON for a worker/week preview.
     */
    public function workerStatement(int $id, string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        $statement = WorkerStatement::where('worker_id', $id)
            ->where('payroll_run_id', $run->id)
            ->first();

        if (! $statement) {
            return $this->error(
                'Statement not yet generated for this worker and week. '
                . 'POST /api/payroll/' . $weekRef . '/generate-statements first.',
                404
            );
        }

        return $this->success([
            'statement'               => $statement->statement_data,
            'generated_at'            => $statement->generated_at,
            'whatsapp_sent'           => $statement->whatsapp_sent,
            'whatsapp_status'         => $statement->whatsapp_status,
            'dispute_window_closes_at'=> $statement->dispute_window_closes_at,
            'dispute_window_open'     => $statement->dispute_window_closes_at
                                            ? now()->isBefore($statement->dispute_window_closes_at)
                                            : false,
        ]);
    }

    // ── POST /api/payroll/{weekRef}/send-statements ──────────────────────────

    /**
     * Enqueue WhatsApp/SMS delivery for all workers who have a generated statement.
     * Returns queued_count and skipped_count (no WhatsApp number or already sent).
     */
    public function sendStatements(string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        if (! in_array($run->status, ['locked', 'paid'])) {
            return $this->error(
                "Cannot send statements for a run that is not locked or paid (current: {$run->status}).",
                409
            );
        }

        $statements = WorkerStatement::where('payroll_run_id', $run->id)
            ->with('worker:id,name,whatsapp')
            ->get();

        $queued  = 0;
        $skipped = 0;

        foreach ($statements as $stmt) {
            // Skip workers with no WhatsApp number and no Twilio SMS config either
            $hasNumber = ! empty($stmt->worker?->whatsapp);
            if (! $hasNumber) {
                $skipped++;
                continue;
            }

            SendWorkerStatementJob::dispatch($stmt->id)->onQueue('notifications');
            $queued++;
        }

        return $this->success([
            'queued_count'  => $queued,
            'skipped_count' => $skipped,
            'message'       => "{$queued} delivery job(s) queued. "
                             . "{$skipped} worker(s) skipped (no contact number). "
                             . 'Check worker_statements.whatsapp_status for results.',
        ]);
    }

    // ── POST /api/payroll/{weekRef}/payment-files ────────────────────────────

    /**
     * Generate all three payment files (JazzCash batch, bank transfer, cash list).
     * Skips a file type when no workers match that payment method.
     * Returns metadata and storage paths for each generated file.
     */
    public function paymentFiles(string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        if (! in_array($run->status, ['locked', 'paid'])) {
            return $this->error(
                "Payment files can only be generated for locked or paid runs (current: {$run->status}).",
                409
            );
        }

        $results = [];
        $errors  = [];

        $generators = [
            'jazzcash_batch' => fn () => $this->paymentFileService->generateJazzCashBatch($run->id),
            'bank_transfer'  => fn () => $this->paymentFileService->generateBankTransferFile($run->id),
            'cash_list'      => fn () => $this->paymentFileService->generateCashList($run->id),
        ];

        foreach ($generators as $type => $generate) {
            try {
                $path = $generate();

                $record = PaymentFile::where('payroll_run_id', $run->id)
                    ->where('file_type', $type)
                    ->first();

                $results[] = [
                    'type'         => $type,
                    'path'         => $path,
                    'worker_count' => $record?->worker_count  ?? 0,
                    'total_amount' => (float) ($record?->total_amount ?? 0),
                    'generated_at' => $record?->generated_at,
                    'download_url' => Storage::disk('local')->exists($path)
                        ? route('payment-files.download', ['path' => base64_encode($path)])
                        : null,
                ];
            } catch (\Throwable $e) {
                $errors[$type] = $e->getMessage();
            }
        }

        return $this->success([
            'files'  => $results,
            'errors' => $errors,
        ]);
    }

    // ── POST /api/workers/{id}/statement/{weekRef}/generate ─────────────────

    /**
     * Generate (or regenerate) the payslip for a single worker + week.
     * The payroll run must be locked or paid.
     */
    public function generateWorkerStatement(int $id, string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        if (! in_array($run->status, ['locked', 'paid'])) {
            return $this->error(
                "Statements can only be generated for locked or paid runs (current: {$run->status}).",
                409
            );
        }

        $worker = Worker::findOrFail($id);

        try {
            $result = $this->statementService->generateStatement($worker->id, $run->id);
        } catch (\Throwable $e) {
            return $this->error("Could not generate statement: {$e->getMessage()}", 422);
        }

        return $this->success([
            'generated' => true,
            'message'   => "Statement generated for {$worker->name} — week {$weekRef}.",
            'data'      => $result,
        ]);
    }

    // ── POST /api/workers/{id}/statement/{weekRef}/send-whatsapp ────────────

    /**
     * Enqueue WhatsApp delivery for a single worker's generated statement.
     */
    public function sendWorkerWhatsApp(int $id, string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        $statement = WorkerStatement::where('worker_id', $id)
            ->where('payroll_run_id', $run->id)
            ->with('worker:id,name,whatsapp')
            ->first();

        if (! $statement) {
            return $this->error(
                'No statement found. Generate the statement first.',
                404
            );
        }

        if (empty($statement->worker?->whatsapp)) {
            return $this->error(
                "Worker has no WhatsApp number on file.",
                422
            );
        }

        SendWorkerStatementJob::dispatch($statement->id)->onQueue('notifications');

        return $this->success([
            'message' => "WhatsApp delivery queued for {$statement->worker->name}.",
        ]);
    }

    // ── POST /api/payroll/{weekRef}/disputes/{worker_id} ─────────────────────

    /**
     * Allow a worker (or their supervisor) to raise a dispute against a statement.
     *
     * Validates:
     *   - Run is locked or paid.
     *   - Statement exists and has been generated.
     *   - Dispute window has not closed.
     *
     * Creates a PayrollException of type 'dispute' and dispatches a notification
     * to the payroll manager queue.
     */
    public function dispute(Request $request, string $weekRef, int $workerId): JsonResponse
    {
        $data = $request->validate([
            'dispute_type' => 'required|string|max:100',
            'details'      => 'required|string|max:2000',
        ]);

        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        if (! in_array($run->status, ['locked', 'paid'])) {
            return $this->error(
                'Disputes can only be submitted for locked or paid payroll runs.',
                422
            );
        }

        $statement = WorkerStatement::where('worker_id', $workerId)
            ->where('payroll_run_id', $run->id)
            ->first();

        if (! $statement) {
            return $this->error(
                'No statement found for this worker and week. Generate the statement first.',
                404
            );
        }

        if ($statement->dispute_window_closes_at && now()->isAfter($statement->dispute_window_closes_at)) {
            return $this->error(
                'The dispute window for this payroll period closed on '
                . $statement->dispute_window_closes_at->toDateString() . '.',
                422
            );
        }

        $wwp = WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
            ->where('worker_id', $workerId)
            ->first();

        $exception = PayrollException::create([
            'payroll_run_id'           => $run->id,
            'worker_id'                => $workerId,
            'worker_weekly_payroll_id' => $wwp?->id,
            'exception_type'           => 'dispute',
            'description'              => "Worker dispute — type: {$data['dispute_type']}. Details: {$data['details']}",
            'amount'                   => null,
        ]);

        // Notify payroll manager asynchronously
        $worker  = Worker::find($workerId);
        dispatch(function () use ($worker, $weekRef, $data, $exception) {
            \Illuminate\Support\Facades\Log::info('[PAYROLL DISPUTE SUBMITTED]', [
                'exception_id' => $exception->id,
                'worker_id'    => $worker?->id,
                'worker_name'  => $worker?->name,
                'week_ref'     => $weekRef,
                'dispute_type' => $data['dispute_type'],
                'details'      => $data['details'],
            ]);
            // Extend here: Mail::to(payroll_manager)->send(new DisputeNotification(...))
        })->onQueue('notifications');

        return $this->created([
            'exception_id' => $exception->id,
            'message'      => 'Dispute submitted. The payroll manager will review it shortly.',
        ]);
    }
}
