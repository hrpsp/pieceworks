<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('weekly_payroll_runs')->cascadeOnDelete();
            $table->string('week_ref');
            $table->json('statement_data');
            $table->timestamp('generated_at');
            $table->boolean('whatsapp_sent')->default(false);
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->string('whatsapp_status')->nullable();
            $table->timestamp('dispute_window_closes_at')->nullable();
            $table->timestamps();

            $table->unique(['worker_id', 'payroll_run_id']);
            $table->index('week_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_statements');
    }
};
