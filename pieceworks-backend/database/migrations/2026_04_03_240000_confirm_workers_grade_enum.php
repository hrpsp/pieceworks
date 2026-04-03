<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CR-003 — Confirm workers.grade enum is canonical.
 *
 * The workers table was created with enum('A','B','C','D','trainee') from day one
 * (migration 2025_01_01_000030), so no junior/senior values ever entered workers.grade.
 *
 * This migration is a no-op ALTER that documents intent: workers.grade must only
 * ever contain A/B/C/D/trainee, matching grade_wage_rates.grade and
 * rate_card_entries.worker_grade (unified by migration 2026_04_03_230000).
 *
 * It also sets the column comment for clarity in database tooling.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Confirm no orphan values before the MODIFY (belt-and-suspenders)
        $orphans = DB::table('workers')
            ->whereNotIn('grade', ['A', 'B', 'C', 'D', 'trainee'])
            ->whereNull('deleted_at')
            ->count();

        if ($orphans > 0) {
            throw new \RuntimeException(
                "workers.grade has {$orphans} row(s) with unexpected values. " .
                "Manually resolve before running this migration."
            );
        }

        // Re-declare the enum with a descriptive comment (safe — no data changes)
        DB::statement(
            "ALTER TABLE workers
             MODIFY COLUMN grade
             ENUM('A','B','C','D','trainee') NOT NULL DEFAULT 'C'
             COMMENT 'A=senior skilled, B=mid-level, C=junior-skilled, D=unskilled, trainee=in-training'"
        );
    }

    public function down(): void
    {
        // Reverting a no-op comment change is itself a no-op
        DB::statement(
            "ALTER TABLE workers
             MODIFY COLUMN grade
             ENUM('A','B','C','D','trainee') NOT NULL DEFAULT 'C'"
        );
    }
};
