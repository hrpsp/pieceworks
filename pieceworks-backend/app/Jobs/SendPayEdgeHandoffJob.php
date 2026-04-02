<?php

namespace App\Jobs;

use App\Services\PayEdgeHandoffService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPayEdgeHandoffJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public readonly int $payrollRunId) {}

    public function handle(PayEdgeHandoffService $service): void
    {
        Log::info("Sending PayEdge handoff for payroll run {$this->payrollRunId}");
        $service->sendWeeklyHandoff($this->payrollRunId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("SendPayEdgeHandoffJob failed", ['payroll_run_id' => $this->payrollRunId, 'error' => $e->getMessage()]);
    }
}
