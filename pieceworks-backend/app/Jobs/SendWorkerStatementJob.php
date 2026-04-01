<?php

namespace App\Jobs;

use App\Services\WorkerStatementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWorkerStatementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $backoff = 10;

    public function __construct(public readonly int $workerStatementId) {}

    public function handle(WorkerStatementService $service): void
    {
        $service->sendWhatsApp($this->workerStatementId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendWorkerStatementJob failed', [
            'worker_statement_id' => $this->workerStatementId,
            'error'               => $e->getMessage(),
        ]);
    }
}
