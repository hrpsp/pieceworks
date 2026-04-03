<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand worker_grade ENUM to include A/B/C grading alongside junior/senior
        DB::statement(
            "ALTER TABLE rate_card_entries
             MODIFY COLUMN worker_grade ENUM('junior','senior','A','B','C') NOT NULL"
        );
    }

    public function down(): void
    {
        // Revert to original two-value ENUM (data using A/B/C must be removed first)
        DB::statement(
            "ALTER TABLE rate_card_entries
             MODIFY COLUMN worker_grade ENUM('junior','senior') NOT NULL"
        );
    }
};
