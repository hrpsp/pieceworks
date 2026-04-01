<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('factory_location')->nullable();
            $table->enum('default_shift', ['morning', 'evening', 'night'])->default('morning');
            // supervisor_id references users (floor supervisors with portal access)
            $table->foreignId('supervisor_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->foreignId('default_contractor_id')
                  ->nullable()
                  ->constrained('contractors')
                  ->nullOnDelete();
            $table->unsignedInteger('capacity_pairs_day')->nullable()->comment('Target output in pairs per day');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lines');
    }
};
