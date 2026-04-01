<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payedge_handoff_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_run_id')
                  ->constrained('weekly_payroll_runs')
                  ->cascadeOnDelete();
            $table->foreignId('worker_id')
                  ->constrained('workers')
                  ->restrictOnDelete();
            $table->string('week_ref', 10);

            $table->enum('status', ['pending', 'sent', 'failed', 'retrying'])
                  ->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0)
                  ->comment('Number of delivery attempts made (max 3)');

            $table->json('payload')->nullable()
                  ->comment('Last request body sent to PayEdge');
            $table->json('response')->nullable()
                  ->comment('Last response body received from PayEdge');
            $table->text('error_message')->nullable();

            $table->timestamp('sent_at')->nullable()
                  ->comment('Timestamp of first successful delivery');
            $table->timestamp('last_attempted_at')->nullable();

            $table->timestamps();

            $table->unique(['payroll_run_id', 'worker_id']);
            $table->index('status');
            $table->index('week_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payedge_handoff_logs');
    }
};
