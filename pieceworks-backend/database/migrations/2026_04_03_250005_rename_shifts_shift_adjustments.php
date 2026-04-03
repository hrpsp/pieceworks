<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CR-004 — Rename shift enum on shift_adjustments (two columns)
 *
 * Columns: scheduled_shift, actual_shift
 * Old: enum('morning','afternoon','night')
 * New: enum('GA','E1','E2','E3','GB')
 *
 * Data mapping: morning→GA  |  afternoon→E1  |  night→E3
 */
return new class extends Migration
{
    public function up(): void
    {
        $wide = "ENUM('morning','afternoon','night','GA','E1','E2','E3','GB') NOT NULL";
        $new  = "ENUM('GA','E1','E2','E3','GB') NOT NULL COMMENT 'GA=day 07-17 | E1=early 06-14 | E2=afternoon 14-22 | E3=night 22-06 | GB=late 17-03'";

        DB::statement("ALTER TABLE shift_adjustments MODIFY COLUMN scheduled_shift {$wide}");
        DB::statement("ALTER TABLE shift_adjustments MODIFY COLUMN actual_shift    {$wide}");

        foreach (['scheduled_shift', 'actual_shift'] as $col) {
            DB::statement("UPDATE shift_adjustments SET {$col}='GA' WHERE {$col}='morning'");
            DB::statement("UPDATE shift_adjustments SET {$col}='E1' WHERE {$col}='afternoon'");
            DB::statement("UPDATE shift_adjustments SET {$col}='E3' WHERE {$col}='night'");
        }

        DB::statement("ALTER TABLE shift_adjustments MODIFY COLUMN scheduled_shift {$new}");
        DB::statement("ALTER TABLE shift_adjustments MODIFY COLUMN actual_shift    {$new}");
    }

    public function down(): void
    {
        $wide = "ENUM('morning','afternoon','night','GA','E1','E2','E3','GB') NOT NULL";
        $old  = "ENUM('morning','afternoon','night') NOT NULL";

        DB::statement("ALTER TABLE shift_adjustments MODIFY COLUMN scheduled_shift {$wide}");
        DB::statement("ALTER TABLE shift_adjustments MODIFY COLUMN actual_shift    {$wide}");

        foreach (['scheduled_shift', 'actual_shift'] as $col) {
            DB::statement("UPDATE shift_adjustments SET {$col}='morning'   WHERE {$col}='GA'");
            DB::statement("UPDATE shift_adjustments SET {$col}='afternoon' WHERE {$col}='E1'");
            DB::statement("UPDATE shift_adjustments SET {$col}='night'     WHERE {$col}='E3'");
        }

        DB::statement("ALTER TABLE shift_adjustments MODIFY COLUMN scheduled_shift {$old}");
        DB::statement("ALTER TABLE shift_adjustments MODIFY COLUMN actual_shift    {$old}");
    }
};
