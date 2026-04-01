<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained()->cascadeOnDelete();
            $table->date('target_date');
            $table->enum('shift', ['morning', 'afternoon', 'night']);
            $table->unsignedInteger('target_pairs');
            $table->timestamps();

            $table->unique(['line_id', 'target_date', 'shift']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_targets');
    }
};
