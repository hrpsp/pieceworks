<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->enum('scheduled_shift', ['morning', 'afternoon', 'night']);
            $table->enum('actual_shift', ['morning', 'afternoon', 'night']);
            $table->foreignId('line_id')->constrained()->cascadeOnDelete();
            $table->decimal('hours_gap_from_last_shift', 5, 2)->nullable();
            $table->boolean('overtime_flagged')->default(false);
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('reason', ['line_shortage', 'skill_requirement', 'worker_request']);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['worker_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_adjustments');
    }
};
