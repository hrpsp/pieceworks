<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CR-005 — Add ot_amount_missing to payroll_exceptions.exception_type ENUM.
 *
 * Triggered when OT hours are recorded but no shift_adjustment amount is found,
 * i.e. the OT premium could not be calculated and has been set to PKR 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE `payroll_exceptions`
            MODIFY COLUMN `exception_type` ENUM(
                'min_wage_shortfall',
                'missing_rate',
                'negative_net_carry',
                'disputed_records',
                'manual',
                'wht_alert',
                'tenure_milestone',
                'compliance_gap',
                'ot_amount_missing'
            ) NOT NULL DEFAULT 'manual'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE `payroll_exceptions`
            MODIFY COLUMN `exception_type` ENUM(
                'min_wage_shortfall',
                'missing_rate',
                'negative_net_carry',
                'disputed_records',
                'manual',
                'wht_alert',
                'tenure_milestone',
                'compliance_gap'
            ) NOT NULL DEFAULT 'manual'
        ");
    }
};
