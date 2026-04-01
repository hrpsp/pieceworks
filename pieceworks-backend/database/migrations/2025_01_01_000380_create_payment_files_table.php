<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('weekly_payroll_runs')->cascadeOnDelete();
            $table->enum('file_type', ['jazzcash_batch', 'bank_transfer', 'cash_list']);
            $table->string('file_path');
            $table->decimal('total_amount', 12, 2);
            $table->unsignedInteger('worker_count');
            $table->timestamp('generated_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'file_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_files');
    }
};
