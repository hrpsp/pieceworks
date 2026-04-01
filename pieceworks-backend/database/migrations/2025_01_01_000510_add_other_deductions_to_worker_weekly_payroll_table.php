<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_weekly_payroll', function (Blueprint $table) {
            // Carries material/equipment/misc deductions applied in priority P4-P6
            $table->decimal('other_deductions', 10, 2)->default(0)->after('loan_deductions');
            // PKR amount that could not be deducted this week (net floor hit)
            $table->decimal('carry_forward_amount', 10, 2)->default(0)->after('other_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('worker_weekly_payroll', function (Blueprint $table) {
            $table->dropColumn(['other_deductions', 'carry_forward_amount']);
        });
    }
};
