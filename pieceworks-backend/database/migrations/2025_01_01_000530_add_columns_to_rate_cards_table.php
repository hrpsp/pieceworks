<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            $table->decimal('training_rate_pct', 5, 2)->default(100.00)
                  ->after('is_active')
                  ->comment('% of base rate applied when worker is still in training period. 100 = no discount.');
            $table->text('notes')->nullable()
                  ->after('training_rate_pct')
                  ->comment('Version notes / change summary for this rate card.');
        });
    }

    public function down(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            $table->dropColumn(['training_rate_pct', 'notes']);
        });
    }
};
