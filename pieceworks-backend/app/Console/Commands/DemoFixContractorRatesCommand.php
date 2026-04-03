<?php

namespace App\Console\Commands;

use App\Models\Contractor;
use Illuminate\Console\Command;

/**
 * CR-008 — demo:fix-contractor-rates
 *
 * Sets the canonical TOR (Terms of Reference) percentages on the three
 * demo contractors that DemoDataSeeder created, then displays the updated
 * values so the operator can verify the fix.
 *
 * Run this once after migrating an existing database that was seeded
 * before CR-008 TOR rates were added to DemoDataSeeder.
 *
 * Usage:
 *   php artisan demo:fix-contractor-rates
 *   php artisan demo:fix-contractor-rates --dry-run
 */
class DemoFixContractorRatesCommand extends Command
{
    protected $signature = 'demo:fix-contractor-rates
                            {--dry-run : Show what would change without writing to the database}';

    protected $description = 'Set canonical TOR rate percentages on demo contractors (Khan=15%, Raza=12%, Premier=18%)';

    /**
     * Canonical TOR rates keyed by contractor name fragment (case-insensitive match).
     */
    private const TOR_RATES = [
        'Khan'    => 15.00,
        'Raza'    => 12.00,
        'Premier' => 18.00,
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be written.');
            $this->newLine();
        }

        $rows    = [];
        $updated = 0;
        $missed  = 0;

        foreach (self::TOR_RATES as $nameFragment => $rate) {
            $contractor = Contractor::where('name', 'like', "%{$nameFragment}%")->first();

            if (! $contractor) {
                $this->warn("  ⚠  No contractor found matching '{$nameFragment}' — skipped.");
                $missed++;
                continue;
            }

            $before = (float) $contractor->tor_rate_pct;
            $change = abs($before - $rate) > 0.001 ? 'CHANGED' : 'already correct';

            if (! $dryRun && abs($before - $rate) > 0.001) {
                $contractor->update(['tor_rate_pct' => $rate]);
                $updated++;
            }

            $rows[] = [
                $contractor->id,
                $contractor->name,
                number_format($before, 2) . '%',
                number_format($rate, 2) . '%',
                $dryRun ? ($change === 'CHANGED' ? 'would change' : $change) : $change,
            ];
        }

        if (! empty($rows)) {
            $this->table(
                ['ID', 'Contractor', 'Before', 'After', 'Status'],
                $rows
            );
        }

        $this->newLine();

        if ($dryRun) {
            $this->info("Dry run complete. {$missed} missed.");
        } else {
            $this->info("{$updated} contractor(s) updated. {$missed} missed.");
        }

        return self::SUCCESS;
    }
}
