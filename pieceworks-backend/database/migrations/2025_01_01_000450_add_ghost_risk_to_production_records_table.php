<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_records', function (Blueprint $table) {
            $table->enum('ghost_risk_level', ['none', 'low', 'medium', 'high'])
                  ->default('none')
                  ->after('supervisor_notes');
            $table->timestamp('ghost_flagged_at')->nullable()->after('ghost_risk_level');
        });
    }

    public function down(): void
    {
        Schema::table('production_records', function (Blueprint $table) {
            $table->dropColumn(['ghost_risk_level', 'ghost_flagged_at']);
        });
    }
};
