<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-001 — Wage-model columns for production_units.
 *
 * Handles two scenarios:
 *  • Fresh install  → table does not yet exist; CREATE it with all columns.
 *  • Incremental    → table already exists;    ALTER it to add the new columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('production_units')) {
            // ── CREATE (fresh migration) ──────────────────────────────────────
            Schema::create('production_units', function (Blueprint $table) {
                $table->id();

                $table->string('name');
                $table->foreignId('line_id')
                      ->nullable()
                      ->constrained('lines')
                      ->nullOnDelete();

                $table->string('operation')->nullable();

                // CR-001: wage model
                $table->enum('wage_model', ['daily_grade', 'per_pair', 'hybrid'])
                      ->default('per_pair');
                $table->unsignedInteger('standard_output_day')
                      ->nullable()
                      ->comment('Hybrid: pairs/day that earns the daily floor; no bonus below this');
                $table->decimal('bonus_rate_per_pair', 8, 2)
                      ->nullable()
                      ->comment('Hybrid: PKR per pair above standard_output_day');

                $table->foreignId('supervisor_id')
                      ->nullable()
                      ->constrained('users')
                      ->nullOnDelete();

                $table->foreignId('default_contractor_id')
                      ->nullable()
                      ->constrained('contractors')
                      ->nullOnDelete();

                $table->unsignedSmallInteger('capacity_workers')->nullable();
                $table->string('status')->default('active');

                $table->timestamps();
                $table->softDeletes();
            });
        } else {
            // ── ALTER (incremental migration on existing DB) ──────────────────
            Schema::table('production_units', function (Blueprint $table) {
                if (! Schema::hasColumn('production_units', 'wage_model')) {
                    $table->enum('wage_model', ['daily_grade', 'per_pair', 'hybrid'])
                          ->default('per_pair')
                          ->after('operation');
                }
                if (! Schema::hasColumn('production_units', 'standard_output_day')) {
                    $table->unsignedInteger('standard_output_day')
                          ->nullable()
                          ->after('wage_model')
                          ->comment('Hybrid: pairs/day that earns the daily floor; no bonus below this');
                }
                if (! Schema::hasColumn('production_units', 'bonus_rate_per_pair')) {
                    $table->decimal('bonus_rate_per_pair', 8, 2)
                          ->nullable()
                          ->after('standard_output_day')
                          ->comment('Hybrid: PKR per pair above standard_output_day');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('production_units')) {
            Schema::table('production_units', function (Blueprint $table) {
                $table->dropColumn(array_filter([
                    Schema::hasColumn('production_units', 'wage_model')           ? 'wage_model'           : null,
                    Schema::hasColumn('production_units', 'standard_output_day')  ? 'standard_output_day'  : null,
                    Schema::hasColumn('production_units', 'bonus_rate_per_pair')  ? 'bonus_rate_per_pair'  : null,
                ]));
            });
        }
    }
};
