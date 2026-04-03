<?php

namespace App\Console\Commands;

use App\Models\WeeklyPayrollRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CR-006 — payroll:sync-run-totals
 *
 * Recalculates run-level aggregate columns (total_gross, total_topups,
 * total_deductions, total_net) from the worker_weekly_payroll child rows.
 *
 * Use-cases:
 *   • Runs created before CR-005 OT split columns were added
 *   • Any run where total_gross = 0 but worker records exist (data drift)
 *   • Manual recovery after a failed mid-run transaction
 *
 * Usage:
 *   php artisan payroll:sync-run-totals            # fix only drifted runs
 *   php artisan payroll:sync-run-totals --all      # force-sync every open run
 *   php artisan payroll:sync-run-totals --week=2026-W15   # one specific run
 */
class PayrollSyncRunTotalsCommand extends Command
{
    protected $signature = 'payroll:sync-run-totals
                            {--all    : Sync ALL non-locked, non-paid runs regardless of drift}
                            {--week=  : Sync a single run by week reference (e.g. 2026-W15)}';

    protected $description = 'Recalculate run-level totals from worker_weekly_payroll records';

    public function handle(): int
    {
        $weekArg = $this->option('week');

        // ── Build query scope ────────────────────────────────────────────────
        $query = WeeklyPayrollRun::query();

        if ($weekArg) {
            $query->where('week_ref', $weekArg);
        } elseif ($this->option('all')) {
            $query->whereNotIn('status', ['locked', 'paid']);
        } else {
            // Default: only runs where total_gross = 0 but worker records exist
            $query->whereNotIn('status', ['locked', 'paid'])
                  ->where('total_gross', 0)
                  ->whereHas('workerPayrolls');
        }

        $runs = $query->get();

        if ($runs->isEmpty()) {
            $this->info('No runs require syncing.');
            return self::SUCCESS;
        }

        $this->info("Syncing totals for {$runs->count()} run(s)...");
        $this->newLine();

        $rows    = [];
        $synced  = 0;
        $skipped = 0;

        foreach ($runs as $run) {
            // Locked / paid runs are immutable — skip even if --all was passed
            if (in_array($run->status, ['locked', 'paid'])) {
                $this->warn("  ⚠  {$run->week_ref} is {$run->status} — skipped.");
                $skipped++;
                continue;
            }

            $payrolls = $run->workerPayrolls()->get();

            if ($payrolls->isEmpty()) {
                $this->line("  –  {$run->week_ref}: no worker records, skipping.");
                $skipped++;
                continue;
            }

            $totalGross      = round((float) $payrolls->sum('gross_earnings'), 2);
            $totalTopups     = round(
                (float) $payrolls->sum('ot_premium')
                + (float) $payrolls->sum('shift_allowance')
                + (float) $payrolls->sum('holiday_pay')
                + (float) $payrolls->sum('min_wage_supplement'),
                2
            );
            $totalDeductions = round(
                (float) $payrolls->sum('advance_deductions')
                + (float) $payrolls->sum('rejection_deductions')
                + (float) $payrolls->sum('loan_deductions')
                + (float) $payrolls->sum('other_deductions'),
                2
            );
            $totalNet        = round((float) $payrolls->sum('net_pay'), 2);

            $run->update([
                'total_gross'      => $totalGross,
                'total_topups'     => $totalTopups,
                'total_deductions' => $totalDeductions,
                'total_net'        => $totalNet,
            ]);

            $rows[] = [
                $run->week_ref,
                $payrolls->count(),
                'PKR ' . number_format($totalGross, 2),
                'PKR ' . number_format($totalTopups, 2),
                'PKR ' . number_format($totalDeductions, 2),
                'PKR ' . number_format($totalNet, 2),
            ];

            $synced++;
        }

        if (! empty($rows)) {
            $this->table(
                ['Week Ref', 'Workers', 'Gross', 'Top-ups', 'Deductions', 'Net'],
                $rows
            );
        }

        $this->newLine();
        $this->info("Done. {$synced} run(s) synced, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
