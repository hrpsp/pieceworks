<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('outstanding_balance');
            $table->unsignedSmallInteger('total_weeks')->nullable()->after('notes')
                  ->comment('ceil(amount / weekly_emi)');
            $table->date('disbursed_at')->nullable()->after('total_weeks');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['notes', 'total_weeks', 'disbursed_at']);
        });
    }
};
