<?php

namespace App\Console\Commands;

use Database\Seeders\BataDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CR-010 — demo:bata-reset
 *
 * Wipes all week 2026-W15 transactional data and re-seeds the Bata demo
 * dataset from scratch via BataDemoSeeder.
 *
 * Safe to run repeatedly in demo / UAT environments.
 * NEVER run against a production database.
 *
 * What gets truncated / deleted:
 *   • payroll_exceptions       WHERE payroll_run_id IN (W15 run ids)
 *   • worker_weekly_payroll    WHERE payroll_run_id IN (W15 run ids)
 *   • contractor_settlements   WHERE week_ref = '2026-W15'
 *   • production_records       WHERE work_date BETWEEN 2026-04-06 AND 2026-04-11
 *   • attendance_records       WHERE work_date BETWEEN 2026-04-06 AND 2026-04-11
 *   • weekly_payroll_runs      WHERE week_ref = '2026-W15'
 *   • workers                  WHERE cnic LIKE '%-911%' (BataDemoSeeder CNICs)
 *   • worker_compliance        (cascades from workers delete)
 *   • demo users               (payroll.manager@bata.demo etc.)
 *   • lines                    WHERE name LIKE 'Line % –%' (BataDemoSeeder lines)
 *   • production_units         (cascades from lines delete)
 *   • factory_locations        WHERE name LIKE '%Lahore Factory%'
 *   • contractors              WHERE ntn_cnic = 'BATA-KLS-001'
 *
 * Usage:
 *   php artisan demo:bata-reset
 *   php artisan demo:bata-reset --force     # skip confirmation prompt
 */
class DemoBataResetCommand extends Command
{
    protected $signature = 'demo:bata-reset
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Reset 2026-W15 Bata demo data and re-seed via BataDemoSeeder';

    private const WEEK_REF   = '2026-W15';
    private const WEEK_START = '2026-04-06';
    private const WEEK_END   = '2026-04-11';

    private const DEMO_EMAILS = [
        'payroll.manager@bata.demo',
        'supervisor@bata.demo',
        'contractor@bata.demo',
        'qc.inspector@bata.demo',
    ];

    public function handle(): int
    {
        if (! $this->option('force')) {
            if (! $this->confirm(
                '⚠  This will DELETE all 2026-W15 demo data and re-seed. Continue?',
                false
            )) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }
        }

        $this->info('Resetting Bata demo data …');
        $this->newLine();

        DB::transaction(function () {
            $this->truncateW15Data();
        });

        $this->info('Re-seeding …');
        $this->newLine();

        $this->call('db:seed', ['--class' => BataDemoSeeder::class]);

        $this->newLine();
        $this->info('Syncing run totals …');
        $this->call('payroll:sync-run-totals', ['--week' => self::WEEK_REF]);

        $this->newLine();
        $this->info('✅  Bata demo reset complete.');

        return self::SUCCESS;
    }

    // ── Private: ordered deletion ─────────────────────────────────────────────

    private function truncateW15Data(): void
    {
        // ── 1. Identify W15 payroll run IDs ──────────────────────────────────
        $runIds = DB::table('weekly_payroll_runs')
            ->where('week_ref', self::WEEK_REF)
            ->pluck('id')
            ->toArray();

        if (! empty($runIds)) {
            $deleted = DB::table('payroll_exceptions')
                ->whereIn('payroll_run_id', $runIds)->delete();
            $this->line("  – payroll_exceptions:     {$deleted} rows deleted");

            $deleted = DB::table('worker_weekly_payroll')
                ->whereIn('payroll_run_id', $runIds)->delete();
            $this->line("  – worker_weekly_payroll:  {$deleted} rows deleted");
        }

        $deleted = DB::table('contractor_settlements')
            ->where('week_ref', self::WEEK_REF)->delete();
        $this->line("  – contractor_settlements: {$deleted} rows deleted");

        $deleted = DB::table('production_records')
            ->whereBetween('work_date', [self::WEEK_START, self::WEEK_END])->delete();
        $this->line("  – production_records:     {$deleted} rows deleted");

        $deleted = DB::table('attendance_records')
            ->whereBetween('work_date', [self::WEEK_START, self::WEEK_END])->delete();
        $this->line("  – attendance_records:     {$deleted} rows deleted");

        if (! empty($runIds)) {
            $deleted = DB::table('weekly_payroll_runs')
                ->whereIn('id', $runIds)->delete();
            $this->line("  – weekly_payroll_runs:    {$deleted} rows deleted");
        }

        // ── 2. Remove demo workers (BataDemoSeeder CNIC pattern: %-9110%-) ───
        $demoWorkerIds = DB::table('workers')
            ->where('cnic', 'like', '%-9110%')
            ->pluck('id')
            ->toArray();

        if (! empty($demoWorkerIds)) {
            // compliance cascades; remove explicitly to be safe
            DB::table('worker_compliance')
                ->whereIn('worker_id', $demoWorkerIds)->delete();

            $deleted = DB::table('workers')
                ->whereIn('id', $demoWorkerIds)->delete();
            $this->line("  – workers:                {$deleted} rows deleted");
        }

        // ── 3. Remove demo users ──────────────────────────────────────────────
        $deleted = DB::table('users')
            ->whereIn('email', self::DEMO_EMAILS)->delete();
        $this->line("  – users (demo):           {$deleted} rows deleted");

        // ── 4. Remove demo lines + production units (cascade) ─────────────────
        $demoLineIds = DB::table('lines')
            ->where('name', 'like', 'Line % –%')
            ->pluck('id')
            ->toArray();

        if (! empty($demoLineIds)) {
            $deleted = DB::table('production_units')
                ->whereIn('line_id', $demoLineIds)->delete();
            $this->line("  – production_units:       {$deleted} rows deleted");

            $deleted = DB::table('lines')
                ->whereIn('id', $demoLineIds)->delete();
            $this->line("  – lines:                  {$deleted} rows deleted");
        }

        // ── 5. Remove demo contractor ─────────────────────────────────────────
        $deleted = DB::table('contractors')
            ->where('ntn_cnic', 'BATA-KLS-001')->delete();
        $this->line("  – contractors (demo):     {$deleted} rows deleted");

        // ── 6. Remove demo factory location ──────────────────────────────────
        $deleted = DB::table('factory_locations')
            ->where('name', 'like', '%Lahore Factory%')->delete();
        $this->line("  – factory_locations:      {$deleted} rows deleted");

        $this->newLine();
        $this->info('  Truncation complete.');
    }
}
