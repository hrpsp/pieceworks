<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')
                  ->constrained('workers')
                  ->restrictOnDelete();
            $table->decimal('amount', 10, 2)->comment('Original principal disbursed');
            $table->decimal('weekly_emi', 10, 2)->comment('Fixed weekly instalment in PKR');
            $table->foreignId('disbursed_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->decimal('outstanding_balance', 10, 2)
                  ->comment('Remaining principal; decremented each payroll week');
            $table->enum('status', ['active', 'fully_paid', 'written_off', 'cancelled'])
                  ->default('active');
            $table->timestamps();

            $table->index(['worker_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
