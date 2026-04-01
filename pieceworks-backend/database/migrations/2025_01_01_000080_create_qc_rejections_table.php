<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_record_id')
                  ->constrained('production_records')
                  ->cascadeOnDelete();
            // Denormalised for reporting queries that don't need to join production_records
            $table->foreignId('worker_id')
                  ->constrained('workers')
                  ->restrictOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('pairs_rejected');
            $table->enum('penalty_type', ['per_pair', 'percentage', 'fixed'])
                  ->default('per_pair');
            $table->decimal('penalty_amount', 10, 2)
                  ->comment('PKR deducted: absolute amount regardless of penalty_type calculation');
            $table->enum('status', ['pending', 'applied', 'disputed', 'reversed'])
                  ->default('pending');
            $table->timestamp('disputed_at')->nullable();
            $table->foreignId('resolved_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->index(['worker_id', 'work_date']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_rejections');
    }
};
