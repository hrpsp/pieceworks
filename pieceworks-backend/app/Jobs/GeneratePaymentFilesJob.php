<?php

namespace App\Jobs;

use App\Services\PaymentFileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GeneratePaymentFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $payrollRunId) {}

    public function handle(PaymentFileService $service): void
    {
        Log::info("Generating payment files for payroll run {$this->payrollRunId}");
        $service->generateJazzCashBatch($this->payrollRunId);
        $service->generateBankTransferFile($this->payrollRunId);
        $service->generateCashList($this->payrollRunId);
        Log::info("Payment files generated for payroll run {$this->payrollRunId}");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("GeneratePaymentFilesJob failed", ['payroll_run_id' => $this->payrollRunId, 'error' => $e->getMessage()]);
    }
}
