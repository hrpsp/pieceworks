<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the enum to include worker-submitted payslip disputes.
        // Full enum list must be specified in MySQL ALTER TABLE … MODIFY COLUMN.
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
                'compliance_gap',
                'dispute'
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
                'manual',
                'wht_alert',
                'tenure_milestone',
                'compliance_gap'
            ) NOT NULL DEFAULT 'manual'
        ");
    }
};
