<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_reversals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_run_id')
                  ->constrained('weekly_payroll_runs')
                  ->cascadeOnDelete();

            $table->enum('reversal_type', ['full', 'partial']);

            // NULL for full-week reversals; set for per-worker partial reversals
            $table->foreignId('worker_id')
                  ->nullable()
                  ->constrained('workers')
                  ->nullOnDelete();

            $table->text('reason');

            $table->foreignId('authorized_by')
                  ->constrained('users')
                  ->restrictOnDelete();

            $table->unsignedInteger('reversed_workers')->default(0)
                  ->comment('Number of worker payroll records affected');
            $table->decimal('total_amount_reversed', 12, 2)->default(0)
                  ->comment('Sum of net_pay values reversed');

            // PayEdge notification tracking
            $table->boolean('payedge_notified')->default(false);
            $table->timestamp('payedge_notified_at')->nullable();
            $table->json('payedge_response')->nullable();

            $table->timestamps();

            $table->index(['payroll_run_id', 'reversal_type']);
            $table->index('worker_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_reversals');
    }
};
