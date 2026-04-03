<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-007 — Add leave_type_id FK to leave_entitlements and bata_dispensary_member to worker_compliance.
 *
 * Changes:
 *   1. leave_entitlements  — adds `leave_type_id` (nullable FK → leave_types.id)
 *      The original `leave_type` enum column is retained for backward compatibility.
 *      A future migration can drop it once all callers use leave_type_id.
 *
 *   2. worker_compliance   — adds `bata_dispensary_member` boolean (default false).
 *      Controls whether a worker on code-D leave receives the dispensary allowance.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. leave_entitlements: add leave_type_id FK ──────────────────────
        Schema::table('leave_entitlements', function (Blueprint $table) {
            $table->foreignId('leave_type_id')
                  ->nullable()
                  ->after('leave_type')
                  ->constrained('leave_types')
                  ->nullOnDelete()
                  ->comment('FK → leave_types.id; replaces the legacy leave_type enum over time');
        });

        // ── 2. worker_compliance: add bata_dispensary_member flag ────────────
        Schema::table('worker_compliance', function (Blueprint $table) {
            $table->boolean('bata_dispensary_member')
                  ->default(false)
                  ->after('wht_applicable')
                  ->comment('True if worker is enrolled in the Bata factory dispensary scheme; unlocks code-D allowance');
        });
    }

    public function down(): void
    {
        Schema::table('leave_entitlements', function (Blueprint $table) {
            $table->dropForeign(['leave_type_id']);
            $table->dropColumn('leave_type_id');
        });

        Schema::table('worker_compliance', function (Blueprint $table) {
            $table->dropColumn('bata_dispensary_member');
        });
    }
};
