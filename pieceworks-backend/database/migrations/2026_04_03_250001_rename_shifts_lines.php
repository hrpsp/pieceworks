<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CR-004 — Rename shift enum on lines.default_shift
 *
 * Old: enum('morning','evening','night')
 * New: enum('GA','E1','E2','E3','GB')
 *
 * Data mapping: morning→GA  |  evening→E2  |  night→E3
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Widen enum to accept both old and new values simultaneously
        DB::statement(
            "ALTER TABLE lines MODIFY COLUMN default_shift
             ENUM('morning','evening','night','GA','E1','E2','E3','GB') NOT NULL DEFAULT 'morning'"
        );

        // Step 2: Migrate data
        DB::statement("UPDATE lines SET default_shift='GA' WHERE default_shift='morning'");
        DB::statement("UPDATE lines SET default_shift='E2' WHERE default_shift='evening'");
        DB::statement("UPDATE lines SET default_shift='E3' WHERE default_shift='night'");

        // Step 3: Lock to new values only
        DB::statement(
            "ALTER TABLE lines MODIFY COLUMN default_shift
             ENUM('GA','E1','E2','E3','GB') NOT NULL DEFAULT 'GA'
             COMMENT 'GA=day 07-17 | E1=early 06-14 | E2=afternoon 14-22 | E3=night 22-06 | GB=late 17-03'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE lines MODIFY COLUMN default_shift
             ENUM('morning','evening','night','GA','E1','E2','E3','GB') NOT NULL DEFAULT 'GA'"
        );
        DB::statement("UPDATE lines SET default_shift='morning' WHERE default_shift='GA'");
        DB::statement("UPDATE lines SET default_shift='evening' WHERE default_shift='E2'");
        DB::statement("UPDATE lines SET default_shift='night'   WHERE default_shift='E3'");
        DB::statement(
            "ALTER TABLE lines MODIFY COLUMN default_shift
             ENUM('morning','evening','night') NOT NULL DEFAULT 'morning'"
        );
    }
};
