<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')
                  ->nullable()
                  ->constrained('contractors')
                  ->nullOnDelete();
            $table->string('name');
            $table->string('cnic', 15)->nullable()->unique()->comment('Format: 00000-0000000-0');
            $table->string('photo_path')->nullable();
            $table->string('biometric_id', 50)->nullable()->unique();
            $table->enum('worker_type', ['permanent', 'contractual', 'trainee'])->default('contractual');
            $table->enum('grade', ['A', 'B', 'C', 'D', 'trainee'])->default('C');
            $table->enum('default_shift', ['morning', 'evening', 'night'])->default('morning');
            $table->foreignId('default_line_id')
                  ->nullable()
                  ->constrained('lines')
                  ->nullOnDelete();
            $table->unsignedSmallInteger('training_period')->nullable()->comment('Duration in days');
            $table->date('training_end_date')->nullable();
            $table->enum('payment_method', ['cash', 'bank', 'easypaisa', 'jazzcash'])->default('cash');
            $table->string('payment_number', 30)->nullable()->comment('Bank account / mobile wallet number');
            $table->string('whatsapp', 20)->nullable();
            $table->string('eobi_number', 20)->nullable();
            $table->string('pessi_number', 20)->nullable();
            $table->date('join_date')->nullable();
            $table->enum('status', ['active', 'inactive', 'terminated'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
