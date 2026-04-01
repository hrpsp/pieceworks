<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenure_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->enum('milestone_days', ['90', '365', '1095', '1825']);
            $table->date('reached_at');
            $table->boolean('alerted')->default(false);
            $table->string('action_taken')->nullable();
            $table->timestamps();

            $table->unique(['worker_id', 'milestone_days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenure_milestones');
    }
};
