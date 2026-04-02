<?php

namespace App\Jobs;

use App\Models\WorkerStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Batch orchestrator: finds all generated but unsent worker statements and
 * dispatches SendWorkerStatementJob for each.
 *
 * Scheduled: Sundays 22:45 (30 min after GenerateAllStatementsJob at 22:15).
 * Also dispatched via PayrollController@release for manual release flows.
 */
class SendAllStatementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    public function __construct(
        /** Optionally scope to a specific payroll run. Null = all unsent. */
        public readonly ?int $payrollRunId = null,
    ) {}

    public function handle(): void
    {
        $query = WorkerStatement::whereNotNull('generated_at')
            ->where('whatsapp_sent', false)
            ->whereNotNull('statement_data');

        if ($this->payrollRunId) {
            $query->where('payroll_run_id', $this->payrollRunId);
        }

        $statements = $query->get();

        if ($statements->isEmpty()) {
            Log::info('SendAllStatementsJob: no unsent statements found, skipping.');
            return;
        }

        $dispatched = 0;
        foreach ($statements as $stmt) {
            // Only send to workers who have a whatsapp number
            if (! empty($stmt->worker?->whatsapp)) {
                SendWorkerStatementJob::dispatch($stmt->id);
                $dispatched++;
            }
        }

        Log::info("SendAllStatementsJob: dispatched {$dispatched} WhatsApp send jobs.");
    }
}
