<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CR-004 — Rename shift enum on production_records.shift
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
        DB::statement(
            "ALTER TABLE production_records MODIFY COLUMN shift
             ENUM('morning','evening','night','GA','E1','E2','E3','GB') NOT NULL"
        );
        DB::statement("UPDATE production_records SET shift='GA' WHERE shift='morning'");
        DB::statement("UPDATE production_records SET shift='E2' WHERE shift='evening'");
        DB::statement("UPDATE production_records SET shift='E3' WHERE shift='night'");
        DB::statement(
            "ALTER TABLE production_records MODIFY COLUMN shift
             ENUM('GA','E1','E2','E3','GB') NOT NULL
             COMMENT 'GA=day 07-17 | E1=early 06-14 | E2=afternoon 14-22 | E3=night 22-06 | GB=late 17-03'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE production_records MODIFY COLUMN shift
             ENUM('morning','evening','night','GA','E1','E2','E3','GB') NOT NULL"
        );
        DB::statement("UPDATE production_records SET shift='morning' WHERE shift='GA'");
        DB::statement("UPDATE production_records SET shift='evening' WHERE shift='E2'");
        DB::statement("UPDATE production_records SET shift='night'   WHERE shift='E3'");
        DB::statement(
            "ALTER TABLE production_records MODIFY COLUMN shift
             ENUM('morning','evening','night') NOT NULL"
        );
    }
};
