<?php

namespace App\Jobs;

use App\Models\WeeklyPayrollRun;
use App\Models\WorkerWeeklyPayroll;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Batch orchestrator: finds the most recent locked payroll run and dispatches
 * GenerateStatementsJob for every worker in that run.
 *
 * Scheduled: Sundays 22:15 (after TriggerWeeklyPayrollRun at 22:00).
 * Also dispatched immediately after payroll lock via PayrollController@lock.
 */
class GenerateAllStatementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    public function handle(): void
    {
        // Find the most recently locked payroll run
        $run = WeeklyPayrollRun::whereIn('status', ['locked', 'paid'])
            ->orderByDesc('end_date')
            ->first();

        if (! $run) {
            Log::info('GenerateAllStatementsJob: no locked payroll run found, skipping.');
            return;
        }

        $workerPayrolls = WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
            ->select('worker_id')
            ->get();

        if ($workerPayrolls->isEmpty()) {
            Log::info("GenerateAllStatementsJob: no workers in run {$run->week_ref}, skipping.");
            return;
        }

        $dispatched = 0;
        foreach ($workerPayrolls as $wp) {
            GenerateStatementsJob::dispatch($wp->worker_id, $run->id);
            $dispatched++;
        }

        Log::info("GenerateAllStatementsJob: dispatched {$dispatched} statement jobs for {$run->week_ref}.");
    }
}
