<?php

namespace App\Jobs;

use App\Services\PayrollCalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateWeeklyPayroll implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public readonly string $weekRef) {}

    public function handle(PayrollCalculationService $service): void
    {
        Log::info("Starting payroll calculation for {$this->weekRef}");
        $service->calculateWeek($this->weekRef);
        Log::info("Payroll calculation complete for {$this->weekRef}");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("CalculateWeeklyPayroll failed for {$this->weekRef}", ['error' => $e->getMessage()]);
    }
}
