<?php

namespace App\Console\Commands;

use App\Models\WeeklyPayrollRun;
use App\Services\PayrollCalculationService;
use Illuminate\Console\Command;

class PayrollCalculateCommand extends Command
{
    protected $signature   = 'payroll:calculate {weekRef : Week reference e.g. 2026-W08}';
    protected $description = 'Manually trigger payroll calculation for a given week reference';

    public function handle(PayrollCalculationService $service): int
    {
        $weekRef = $this->argument('weekRef');
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->first();

        if (!$run) {
            $this->error("No payroll run found for week reference: {$weekRef}");
            return self::FAILURE;
        }

        if ($run->status === 'locked' || $run->status === 'paid') {
            $this->error("Payroll run {$weekRef} is already {$run->status}. Cannot recalculate.");
            return self::FAILURE;
        }

        $this->info("Calculating payroll for {$weekRef}...");
        $bar = $this->output->createProgressBar();
        $bar->start();

        $service->calculateWeek($weekRef);

        $bar->finish();
        $this->newLine();

        $this->info("Payroll calculation complete for {$weekRef}.");
        $run->refresh();

        $this->table(
            ['Week Ref', 'Total Gross', 'Total Deductions', 'Total Net', 'Status'],
            [[
                $run->week_ref,
                'PKR ' . number_format($run->total_gross, 2),
                'PKR ' . number_format($run->total_deductions, 2),
                'PKR ' . number_format($run->total_net, 2),
                $run->status,
            ]]
        );

        return self::SUCCESS;
    }
}
