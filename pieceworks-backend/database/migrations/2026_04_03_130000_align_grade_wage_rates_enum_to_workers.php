<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grade alignment fix for CR-001.
 *
 * The initial CR-001 migration created grade_wage_rates.grade with enum
 * ['grade_1'…'grade_10'], but workers.grade uses ['A','B','C','D','trainee'].
 * The RateEngineService looks up GradeWageRate by worker->grade, so the enum
 * values must match the values stored on workers.
 *
 * This migration drops and recreates grade_wage_rates with the correct enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the mismatched table (cascading removes any FK-referencing data)
        Schema::dropIfExists('grade_wage_rates');

        // Recreate with enum values that match workers.grade
        Schema::create('grade_wage_rates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rate_card_id')
                  ->constrained('rate_cards')
                  ->onDelete('cascade');

            // Must match workers.grade enum: ['A','B','C','D','trainee']
            $table->enum('grade', ['A', 'B', 'C', 'D', 'trainee'])
                  ->comment('Matches workers.grade — one daily wage per grade per rate card');

            $table->decimal('daily_wage_pkr', 10, 2)
                  ->comment('Fixed daily wage in PKR for this grade under this rate card');

            $table->timestamps();

            // One wage entry per grade per rate card version
            $table->unique(['rate_card_id', 'grade']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_wage_rates');

        // Restore the original (mismatched) schema if rolling back
        Schema::create('grade_wage_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_card_id')->constrained('rate_cards')->onDelete('cascade');
            $table->enum('grade', [
                'grade_1','grade_2','grade_3','grade_4','grade_5',
                'grade_6','grade_7','grade_8','grade_9','grade_10',
            ]);
            $table->decimal('daily_wage_pkr', 10, 2);
            $table->timestamps();
            $table->unique(['rate_card_id', 'grade']);
        });
    }
};
