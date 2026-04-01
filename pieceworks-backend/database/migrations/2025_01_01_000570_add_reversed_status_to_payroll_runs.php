<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE weekly_payroll_runs
            MODIFY COLUMN status
                ENUM('open','processing','locked','paid','reversed')
                NOT NULL DEFAULT 'open'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE weekly_payroll_runs
            MODIFY COLUMN status
                ENUM('open','processing','locked','paid')
                NOT NULL DEFAULT 'open'
        ");
    }
};
