<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')
                  ->constrained('workers')
                  ->cascadeOnDelete();
            $table->foreignId('line_id')
                  ->constrained('lines')
                  ->restrictOnDelete();
            // Nullable: manual/backfill records may not map to a rate card entry
            $table->foreignId('rate_card_entry_id')
                  ->nullable()
                  ->constrained('rate_card_entries')
                  ->nullOnDelete();
            $table->date('work_date');
            $table->enum('shift', ['morning', 'evening', 'night']);
            $table->foreignId('style_sku_id')
                  ->nullable()
                  ->constrained('style_sku')
                  ->nullOnDelete();
            $table->string('task')->comment('Stitching, Lasting, Sole Attaching, etc.');
            $table->unsignedInteger('pairs_produced');
            // Snapshot the rate at recording time — rate card may change later
            $table->decimal('rate_amount', 10, 2)->comment('PKR per pair at time of recording');
            $table->decimal('gross_earnings', 10, 2)->comment('pairs_produced × rate_amount');

            $table->enum('source_tag', ['bata_api', 'manual_supervisor', 'manual_backfill'])
                  ->default('manual_supervisor');

            // Shift-level adjustment (bonus or penalty applied to the whole shift block)
            $table->decimal('shift_adjustment', 10, 2)->default(0);
            $table->foreignId('shift_adj_authorized_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->text('shift_adj_reason')->nullable();

            $table->enum('validation_status', ['pending', 'validated', 'disputed', 'rejected'])
                  ->default('pending');
            $table->boolean('is_locked')->default(false)
                  ->comment('Locked after payroll run; no further edits allowed');

            $table->timestamps();

            // Common query patterns
            $table->index(['worker_id', 'work_date']);
            $table->index(['line_id', 'work_date', 'shift']);
            $table->index(['work_date', 'validation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_records');
    }
};
