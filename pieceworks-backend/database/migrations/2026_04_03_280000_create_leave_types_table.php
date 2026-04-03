<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-007 — Create leave_types master table.
 *
 * Replaces the inline enum('annual','casual','sick') on leave_entitlements
 * with a proper FK-linked master table that supports all 15 Bata leave codes.
 *
 * pay_type values:
 *   full            – worker paid at normal daily rate for each leave day
 *   half            – worker paid at 50% of daily rate
 *   none            – no pay (unauthorised / unpaid)
 *   allowance_only  – no production pay but dispensary/medical allowance applies
 *
 * The original leave_entitlements.leave_type enum is retained and NOT dropped
 * here — see the companion migration 2026_04_03_290000 which adds the FK column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();

            // Bata single-letter code (A, B, S, D, C, E, O, F, Z, T, H, L, Q, U, R)
            $table->char('code', 1)->unique()
                  ->comment('Bata leave code: A=Annual, B=Bereavement, S=Sick, D=Dispensary, C=Casual, E=Emergency, O=Off, F=Factory Holiday, Z=Unpaid, T=Training, H=Hajj, L=Late, Q=Quarantine, U=Unauthorised, R=Rest/Compensatory');

            $table->string('name');

            $table->enum('pay_type', ['full', 'half', 'none', 'allowance_only'])
                  ->default('full')
                  ->comment('Payroll treatment: full=100%, half=50%, none=no pay, allowance_only=dispensary benefit only');

            // Optional daily allowance (e.g. dispensary subsidy in PKR)
            $table->decimal('allowance_per_day', 8, 2)->default(0.00)
                  ->comment('Additional PKR allowance per leave day (used for allowance_only type)');

            // Half-day eligibility flag (L = Late / short absence)
            $table->boolean('half_day_eligible')->default(false)
                  ->comment('If true, this leave type can be applied as a half-day deduction');

            // Quota limits (0 = unlimited / governed by entitlement table)
            $table->unsignedSmallInteger('max_days_per_week')->default(0)
                  ->comment('Max days per week; 0 = no weekly cap');
            $table->unsignedSmallInteger('max_days_per_year')->default(0)
                  ->comment('Max days per year; 0 = governed by leave_entitlements.entitled_days');

            // Whether this type appears on payslip and entitlement reports
            $table->enum('applicable_to', ['all', 'permanent', 'contractor', 'trainee'])
                  ->default('all')
                  ->comment('Worker categories for which this leave code is valid');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
