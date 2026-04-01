<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained('contractors')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('weekly_payroll_runs')->cascadeOnDelete();
            $table->string('week_ref');
            $table->unsignedInteger('total_pairs')->default(0);
            $table->decimal('contracted_rate_avg', 10, 4)->nullable();
            $table->decimal('bata_owes', 12, 2)->default(0);
            $table->decimal('workers_paid', 12, 2)->default(0);
            $table->decimal('contractor_margin', 12, 2)->default(0);
            $table->enum('settlement_status', ['pending', 'settled', 'disputed'])->default('pending');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['contractor_id', 'payroll_run_id']);
            $table->index('week_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_settlements');
    }
};
