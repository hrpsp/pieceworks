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
        Schema::table('production_records', function (Blueprint $table) {
            $table->enum('wage_model_applied', ['daily_grade', 'per_pair', 'hybrid'])
                  ->nullable()
                  ->after('source_tag')
                  ->comment('Wage model in effect at the time this record was created');

            $table->string('rate_detail', 300)
                  ->nullable()
                  ->after('wage_model_applied')
                  ->comment(
                      'Human-readable wage calculation breakdown, e.g. ' .
                      '"Grade 5 daily wage", ' .
                      '"88 pairs x PKR 35 (Stitching / Standard / Senior)", ' .
                      '"Floor PKR 1,400 (Grade 5) + 8 bonus pairs x PKR 15 = PKR 1,520"'
                  );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_records', function (Blueprint $table) {
            $table->dropColumn([
                'wage_model_applied',
                'rate_detail',
            ]);
        });
    }
};
