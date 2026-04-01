<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the payroll_exceptions.exception_type enum to include
 * compliance-related types added in the statutory compliance feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE payroll_exceptions
            MODIFY COLUMN exception_type ENUM(
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

    public function down(): void
    {
        DB::statement("
            ALTER TABLE payroll_exceptions
            MODIFY COLUMN exception_type ENUM(
                'min_wage_shortfall',
                'missing_rate',
                'negative_net_carry',
                'disputed_records',
                'manual'
            ) NOT NULL DEFAULT 'manual'
        ");
    }
};
