<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contractor portal users: a User row with role = 'contractor' and
     * a contractor_id link grants scoped read access to their contractor's data.
     */
    public function up(): void
    {
        // 1. Extend the role enum with 'contractor'
        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN role ENUM('admin', 'payroll_manager', 'supervisor', 'contractor')
            NOT NULL DEFAULT 'supervisor'
        ");

        // 2. Add contractor_id FK
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('contractor_id')
                  ->nullable()
                  ->after('role')
                  ->constrained('contractors')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['contractor_id']);
            $table->dropColumn('contractor_id');
        });

        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN role ENUM('admin', 'payroll_manager', 'supervisor')
            NOT NULL DEFAULT 'supervisor'
        ");
    }
};
