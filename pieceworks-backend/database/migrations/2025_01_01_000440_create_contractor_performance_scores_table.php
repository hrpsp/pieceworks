<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_performance_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained('contractors')->cascadeOnDelete();
            $table->string('week_ref');
            $table->decimal('delivery_score', 5, 2)->nullable();
            $table->decimal('rejection_rate', 5, 4)->nullable();
            $table->decimal('compliance_score', 5, 2)->nullable();
            $table->unsignedSmallInteger('min_wage_shortfall_count')->default(0);
            $table->unsignedSmallInteger('ghost_worker_flags')->default(0);
            $table->decimal('composite_score', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['contractor_id', 'week_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_performance_scores');
    }
};
