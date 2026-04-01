<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_payroll_runs', function (Blueprint $table) {
            $table->id();
            // ISO week reference, e.g. '2025-W12'. Unique — one run per week.
            $table->string('week_ref', 10)->unique()->comment('ISO format: YYYY-W##');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'processing', 'locked', 'paid'])->default('open');

            // Aggregate totals (computed & stored when locked for audit trail)
            $table->decimal('total_gross', 12, 2)->default(0);
            $table->decimal('total_topups', 12, 2)->default(0)
                  ->comment('OT premium + shift allowance + holiday pay + min wage supplement');
            $table->decimal('total_deductions', 12, 2)->default(0)
                  ->comment('Advances + QC penalties + loan EMIs');
            $table->decimal('total_net', 12, 2)->default(0);

            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('released_at')->nullable()
                  ->comment('Timestamp when payments were released/disbursed');
            $table->foreignId('released_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            $table->index(['status']);
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_payroll_runs');
    }
};
