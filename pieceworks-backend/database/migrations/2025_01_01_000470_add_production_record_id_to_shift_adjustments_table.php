<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_adjustments', function (Blueprint $table) {
            // Link back to the production record that triggered this adjustment
            $table->foreignId('production_record_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('production_records')
                  ->nullOnDelete();

            // Allow null reason during auto-detection (supervisor fills in on confirm)
            $table->string('reason_text')->nullable()->after('reason');

            $table->index('production_record_id');
        });
    }

    public function down(): void
    {
        Schema::table('shift_adjustments', function (Blueprint $table) {
            $table->dropForeign(['production_record_id']);
            $table->dropColumn(['production_record_id', 'reason_text']);
        });
    }
};
