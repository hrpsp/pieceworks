<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')
                  ->constrained('weekly_payroll_runs')
                  ->cascadeOnDelete();
            $table->foreignId('worker_id')
                  ->constrained('workers')
                  ->restrictOnDelete();
            $table->foreignId('worker_weekly_payroll_id')
                  ->nullable()
                  ->constrained('worker_weekly_payroll')
                  ->cascadeOnDelete();
            $table->enum('exception_type', [
                'min_wage_shortfall',
                'missing_rate',
                'negative_net_carry',
                'disputed_records',
                'manual',
            ])->default('manual');
            $table->text('description');
            $table->decimal('amount', 10, 2)->nullable()
                  ->comment('Contextual amount, e.g. shortfall PKR or carry-forward PKR');
            // Resolution
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'resolved_at']);
            $table->index(['worker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_exceptions');
    }
};
