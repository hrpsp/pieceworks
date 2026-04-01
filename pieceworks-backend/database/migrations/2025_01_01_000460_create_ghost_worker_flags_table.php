<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ghost_worker_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_record_id')->nullable()->constrained('production_records')->nullOnDelete();
            $table->date('work_date');
            $table->enum('risk_level', ['medium', 'high']);
            $table->boolean('biometric_present')->default(false);
            $table->boolean('production_anomaly')->default(false);
            $table->decimal('pairs_produced', 10, 2)->nullable();
            $table->decimal('four_week_avg', 10, 2)->nullable();
            $table->decimal('std_dev', 10, 2)->nullable();
            // Override / resolution
            $table->timestamp('overridden_at')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['worker_id', 'work_date']);
            $table->index(['risk_level', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghost_worker_flags');
    }
};
