<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->enum('status', ['present', 'absent', 'idle', 'zero_production']);
            $table->text('idle_reason')->nullable();
            $table->timestamp('biometric_punch_in')->nullable();
            $table->timestamp('biometric_punch_out')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['biometric', 'manual'])->default('manual');
            $table->timestamps();

            $table->unique(['worker_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
