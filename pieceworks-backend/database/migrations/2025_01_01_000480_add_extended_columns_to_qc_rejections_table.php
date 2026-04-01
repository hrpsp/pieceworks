<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qc_rejections', function (Blueprint $table) {
            // Defect classification
            $table->string('defect_type', 100)->nullable()->after('pairs_rejected');

            // New penalty mode (the spec's terminology).
            // The original penalty_type column (per_pair|percentage|fixed) is kept
            // for backwards-compat; this new column drives the API logic.
            $table->enum('penalty_mode', ['reduce_pairs', 'flat_penalty', 'flag_only'])
                  ->default('flag_only')
                  ->after('defect_type');

            // Actual pairs removed from the production record (only for reduce_pairs)
            $table->unsignedInteger('pairs_deducted')->default(0)->after('penalty_mode');

            // Dispute fields
            $table->text('dispute_reason')->nullable()->after('disputed_at');
            $table->foreignId('disputed_by')->nullable()->after('dispute_reason')
                  ->constrained('users')->nullOnDelete();

            // Resolution fields
            $table->enum('resolution', ['accept', 'reverse'])->nullable()->after('resolved_by');
            $table->text('resolution_notes')->nullable()->after('resolution');
            $table->timestamp('resolved_at')->nullable()->after('resolution_notes');

            // Track whether a carry-forward credit was already created on reversal
            $table->boolean('credit_created')->default(false)->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('qc_rejections', function (Blueprint $table) {
            $table->dropForeign(['disputed_by']);
            $table->dropColumn([
                'defect_type', 'penalty_mode', 'pairs_deducted',
                'dispute_reason', 'disputed_by',
                'resolution', 'resolution_notes', 'resolved_at',
                'credit_created',
            ]);
        });
    }
};
