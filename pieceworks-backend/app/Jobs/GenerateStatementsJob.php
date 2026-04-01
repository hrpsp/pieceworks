<?php

namespace App\Jobs;

use App\Services\WorkerStatementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateStatementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 5;

    public function __construct(
        public readonly int $workerId,
        public readonly int $payrollRunId,
    ) {}

    public function handle(WorkerStatementService $service): void
    {
        $service->generateStatement($this->workerId, $this->payrollRunId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GenerateStatementsJob failed', [
            'worker_id'      => $this->workerId,
            'payroll_run_id' => $this->payrollRunId,
            'error'          => $e->getMessage(),
        ]);
    }
}
