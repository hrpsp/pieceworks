<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->decimal('amount_deducted', 10, 2)->default(0)
                  ->after('carry_weeks')
                  ->comment('Running total of PKR already deducted across payroll runs');
        });
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->dropColumn('amount_deducted');
        });
    }
};
