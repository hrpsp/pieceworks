<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * billing_contractor_id tracks WHICH contractor is billed for a production record.
     *
     * Normally this is the line's default_contractor_id. When a worker from Contractor A
     * produces on a line belonging to Contractor B, the billing_contractor_id = B,
     * so Bata's settlement with B includes those pairs even though the worker belongs to A.
     *
     * NULL means the record predates this column; settlement falls back to worker.contractor_id.
     */
    public function up(): void
    {
        Schema::table('production_records', function (Blueprint $table) {
            $table->foreignId('billing_contractor_id')
                  ->nullable()
                  ->after('source_tag')
                  ->constrained('contractors')
                  ->nullOnDelete();

            $table->index('billing_contractor_id');
        });
    }

    public function down(): void
    {
        Schema::table('production_records', function (Blueprint $table) {
            $table->dropForeign(['billing_contractor_id']);
            $table->dropIndex(['billing_contractor_id']);
            $table->dropColumn('billing_contractor_id');
        });
    }
};
