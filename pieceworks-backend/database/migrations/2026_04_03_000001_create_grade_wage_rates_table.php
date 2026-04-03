<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('grade_wage_rates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rate_card_id')
                  ->constrained('rate_cards')
                  ->cascadeOnDelete();

            $table->enum('grade', [
                'grade_1',
                'grade_2',
                'grade_3',
                'grade_4',
                'grade_5',
                'grade_6',
                'grade_7',
                'grade_8',
                'grade_9',
                'grade_10',
            ]);

            $table->decimal('daily_wage_pkr', 10, 2);

            $table->timestamps();

            $table->unique(['rate_card_id', 'grade']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grade_wage_rates');
    }
};
