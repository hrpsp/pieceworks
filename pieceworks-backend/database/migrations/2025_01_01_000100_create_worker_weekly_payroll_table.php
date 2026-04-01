<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_weekly_payroll', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')
                  ->constrained('weekly_payroll_runs')
                  ->cascadeOnDelete();
            $table->foreignId('worker_id')
                  ->constrained('workers')
                  ->restrictOnDelete();
            // Denormalised — contractor may change; snapshot at payroll time
            $table->foreignId('contractor_id')
                  ->nullable()
                  ->constrained('contractors')
                  ->nullOnDelete();

            // Earnings breakdown
            $table->decimal('gross_earnings', 10, 2)->default(0)
                  ->comment('Sum of production_records.gross_earnings for the week');
            $table->decimal('ot_premium', 10, 2)->default(0);
            $table->decimal('shift_allowance', 10, 2)->default(0);
            $table->decimal('holiday_pay', 10, 2)->default(0);
            $table->decimal('min_wage_supplement', 10, 2)->default(0)
                  ->comment('Top-up to meet minimum wage if piece-rate earnings fall short');
            $table->decimal('total_gross', 10, 2)->default(0)
                  ->comment('gross_earnings + ot_premium + shift_allowance + holiday_pay + min_wage_supplement');

            // Deductions breakdown
            $table->decimal('advance_deductions', 10, 2)->default(0);
            $table->decimal('rejection_deductions', 10, 2)->default(0);
            $table->decimal('loan_deductions', 10, 2)->default(0);

            $table->decimal('net_pay', 10, 2)->default(0)
                  ->comment('total_gross − advance_deductions − rejection_deductions − loan_deductions');

            $table->enum('payment_method', ['cash', 'bank', 'easypaisa', 'jazzcash'])
                  ->default('cash');
            $table->enum('payment_status', ['pending', 'processing', 'paid', 'failed'])
                  ->default('pending');

            $table->timestamps();

            // One record per worker per payroll run
            $table->unique(['payroll_run_id', 'worker_id']);
            $table->index(['worker_id', 'payroll_run_id']);
            $table->index(['payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_weekly_payroll');
    }
};
