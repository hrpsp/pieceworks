<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('amount');
            $table->text('notes')->nullable()->after('requires_approval');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            // How many weeks this advance has already been carried over (not reduced)
            $table->unsignedSmallInteger('carried_weeks')->default(0)->after('amount_deducted');
        });
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->dropColumn(['requires_approval', 'notes', 'approved_at', 'carried_weeks']);
        });
    }
};
