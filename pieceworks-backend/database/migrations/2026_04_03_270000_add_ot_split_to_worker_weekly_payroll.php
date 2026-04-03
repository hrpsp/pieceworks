<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-005 — Add split OT tracking columns to worker_weekly_payroll.
 *
 * The existing ot_premium column captures a single flat OT premium amount
 * sourced from shift_adjustment records. This migration adds six new columns
 * that break OT into three categories with both hours and amount tracked:
 *
 *   regular OT – hours above the weekly threshold (45h standard / 48h Watch & Ward)
 *   night OT   – additional premium for shifts E2, E3, GB
 *   extra OT   – any hours beyond a configurable extra ceiling (default 0)
 *
 * The original ot_premium column is retained and NOT dropped here (see comment below).
 * A future migration can drop ot_premium once the OT engine is fully migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_weekly_payroll', function (Blueprint $table) {
            // Regular OT (all workers, hours above weekly threshold)
            $table->decimal('ot_regular_hours',  5, 2)->default(0)->after('ot_premium')
                  ->comment('Hours above weekly threshold (45h standard / 48h Watch & Ward)');
            $table->decimal('ot_regular_amount', 10, 2)->default(0)->after('ot_regular_hours')
                  ->comment('PKR premium for regular OT hours');

            // Night OT (E2 / E3 / GB shifts only)
            $table->decimal('ot_night_hours',    5, 2)->default(0)->after('ot_regular_amount')
                  ->comment('OT hours attracting night premium (shifts E2, E3, GB)');
            $table->decimal('ot_night_amount',   10, 2)->default(0)->after('ot_night_hours')
                  ->comment('PKR premium for night OT hours');

            // Extra OT (beyond a configurable ceiling — normally 0)
            $table->decimal('ot_extra_hours',    5, 2)->default(0)->after('ot_night_amount')
                  ->comment('OT hours beyond the extra ceiling threshold');
            $table->decimal('ot_extra_amount',   10, 2)->default(0)->after('ot_extra_hours')
                  ->comment('PKR premium for extra OT hours');

            // NOTE: ot_premium (existing) is kept as the legacy/rollup column.
            // It continues to receive the total OT premium during the transition period.
            // Drop it in a future migration once all callers use the split columns.
        });
    }

    public function down(): void
    {
        Schema::table('worker_weekly_payroll', function (Blueprint $table) {
            $table->dropColumn([
                'ot_regular_hours', 'ot_regular_amount',
                'ot_night_hours',   'ot_night_amount',
                'ot_extra_hours',   'ot_extra_amount',
            ]);
        });
    }
};
