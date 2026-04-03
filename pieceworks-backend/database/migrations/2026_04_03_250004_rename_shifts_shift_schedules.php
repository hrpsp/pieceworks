<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CR-004 — Rename shift enum on shift_schedules.shift
 *
 * Old: enum('morning','afternoon','night')   ← note 'afternoon' not 'evening'
 * New: enum('GA','E1','E2','E3','GB')
 *
 * Data mapping: morning→GA  |  afternoon→E1  |  night→E3
 * (afternoon maps to E1 early shift — closest equivalent to the old afternoon code)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE shift_schedules MODIFY COLUMN shift
             ENUM('morning','afternoon','night','GA','E1','E2','E3','GB') NOT NULL"
        );
        DB::statement("UPDATE shift_schedules SET shift='GA' WHERE shift='morning'");
        DB::statement("UPDATE shift_schedules SET shift='E1' WHERE shift='afternoon'");
        DB::statement("UPDATE shift_schedules SET shift='E3' WHERE shift='night'");
        DB::statement(
            "ALTER TABLE shift_schedules MODIFY COLUMN shift
             ENUM('GA','E1','E2','E3','GB') NOT NULL
             COMMENT 'GA=day 07-17 | E1=early 06-14 | E2=afternoon 14-22 | E3=night 22-06 | GB=late 17-03'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE shift_schedules MODIFY COLUMN shift
             ENUM('morning','afternoon','night','GA','E1','E2','E3','GB') NOT NULL"
        );
        DB::statement("UPDATE shift_schedules SET shift='morning'   WHERE shift='GA'");
        DB::statement("UPDATE shift_schedules SET shift='afternoon' WHERE shift='E1'");
        DB::statement("UPDATE shift_schedules SET shift='night'     WHERE shift='E3'");
        DB::statement(
            "ALTER TABLE shift_schedules MODIFY COLUMN shift
             ENUM('morning','afternoon','night') NOT NULL"
        );
    }
};
