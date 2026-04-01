<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')
                  ->constrained('workers')
                  ->restrictOnDelete();
            // Week the advance was requested/issued, e.g. '2025-W12'
            $table->string('week_ref', 10)->comment('ISO format: YYYY-W##');
            $table->decimal('amount', 10, 2);
            $table->foreignId('approved_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->enum('payment_method', ['cash', 'bank', 'easypaisa', 'jazzcash'])
                  ->default('cash');
            // Week from which deduction should start, e.g. '2025-W13'
            $table->string('deduction_week', 10)->nullable()
                  ->comment('ISO week ref when deduction begins');
            // Spread the advance deduction over N payroll weeks (default: full amount in 1 week)
            $table->unsignedSmallInteger('carry_weeks')->default(1)
                  ->comment('Number of weeks over which to spread deduction');
            $table->enum('status', ['pending', 'approved', 'partially_deducted', 'fully_deducted', 'cancelled'])
                  ->default('pending');
            $table->timestamps();

            $table->index(['worker_id', 'status']);
            $table->index(['deduction_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advances');
    }
};
