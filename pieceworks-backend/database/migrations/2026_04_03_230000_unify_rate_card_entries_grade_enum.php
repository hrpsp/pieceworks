<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unify rate_card_entries.worker_grade enum to match workers.grade exactly.
 *
 * Before: ENUM('junior','senior','A','B','C')  — mixed legacy/new values
 * After:  ENUM('A','B','C','D','trainee')       — matches workers.grade
 *
 * Any rows using 'junior' or 'senior' must be deleted before the ALTER,
 * because MySQL will refuse to change an enum if live data uses removed values.
 *
 * Background: migration 2026_04_03_120000 expanded the enum to include A/B/C
 * alongside the original junior/senior, but never completed the cleanup.
 * This migration finishes the job.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Remove any entries still using the legacy grade labels.
        //    These are entries on the old 'vv4' card that used junior/senior
        //    before it was reseeded with proper A/B/C rates.
        DB::table('rate_card_entries')
            ->whereIn('worker_grade', ['junior', 'senior'])
            ->delete();

        // 2. Alter the enum — safe now that no rows use the removed values.
        DB::statement(
            "ALTER TABLE rate_card_entries
             MODIFY COLUMN worker_grade
             ENUM('A','B','C','D','trainee') NOT NULL
             COMMENT 'Matches workers.grade — A=senior, B=mid, C=junior-skilled, D=unskilled, trainee'"
        );
    }

    public function down(): void
    {
        // Restore the intermediate state (junior/senior/A/B/C).
        // Note: rows using D or trainee would need to be removed first
        // if rolling back to a state that doesn't support those values.
        DB::statement(
            "ALTER TABLE rate_card_entries
             MODIFY COLUMN worker_grade
             ENUM('junior','senior','A','B','C') NOT NULL"
        );
    }
};
