<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * CR-007 — LeaveTypesSeeder
 *
 * Seeds the leave_types master table with all 15 Bata Pakistan leave codes.
 * Idempotent: uses upsert on the `code` column so it can be re-run safely.
 *
 * Pay-type reference:
 *   full           – 100% daily wage during leave
 *   half           – 50% daily wage during leave
 *   none           – no pay (e.g. unauthorised absence, unpaid off)
 *   allowance_only – dispensary subsidy only (code D); no production pay
 */
class LeaveTypesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->toDateTimeString();

        $types = [
            // ─── Paid / full-pay leaves ────────────────────────────────────
            [
                'code'               => 'A',
                'name'               => 'Annual Leave',
                'pay_type'           => 'full',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => false,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 0,    // governed by leave_entitlements.entitled_days
                'applicable_to'      => 'permanent',
                'is_active'          => true,
            ],
            [
                'code'               => 'B',
                'name'               => 'Bereavement Leave',
                'pay_type'           => 'full',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => false,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 3,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],
            [
                'code'               => 'S',
                'name'               => 'Sick Leave',
                'pay_type'           => 'full',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => true,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 0,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],
            [
                'code'               => 'C',
                'name'               => 'Casual Leave',
                'pay_type'           => 'full',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => true,
                'max_days_per_week'  => 1,
                'max_days_per_year'  => 10,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],
            [
                'code'               => 'F',
                'name'               => 'Factory Holiday',
                'pay_type'           => 'full',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => false,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 0,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],
            [
                'code'               => 'T',
                'name'               => 'Training Leave',
                'pay_type'           => 'full',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => false,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 0,
                'applicable_to'      => 'permanent',
                'is_active'          => true,
            ],
            [
                'code'               => 'H',
                'name'               => 'Hajj Leave',
                'pay_type'           => 'full',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => false,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 30,   // once in employment lifetime typically
                'applicable_to'      => 'permanent',
                'is_active'          => true,
            ],
            [
                'code'               => 'Q',
                'name'               => 'Quarantine Leave',
                'pay_type'           => 'full',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => false,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 14,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],
            [
                'code'               => 'R',
                'name'               => 'Rest Day / Compensatory Off',
                'pay_type'           => 'full',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => false,
                'max_days_per_week'  => 1,
                'max_days_per_year'  => 0,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],

            // ─── Half-pay leaves ───────────────────────────────────────────
            [
                'code'               => 'E',
                'name'               => 'Emergency Leave',
                'pay_type'           => 'half',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => true,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 5,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],
            [
                'code'               => 'L',
                'name'               => 'Late Arrival / Short Absence',
                'pay_type'           => 'half',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => true,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 0,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],

            // ─── No-pay leaves ─────────────────────────────────────────────
            [
                'code'               => 'O',
                'name'               => 'Off Day (Unpaid)',
                'pay_type'           => 'none',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => false,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 0,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],
            [
                'code'               => 'Z',
                'name'               => 'Unpaid / Leave Without Pay',
                'pay_type'           => 'none',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => false,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 0,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],
            [
                'code'               => 'U',
                'name'               => 'Unauthorised Absence',
                'pay_type'           => 'none',
                'allowance_per_day'  => 0.00,
                'half_day_eligible'  => false,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 0,
                'applicable_to'      => 'all',
                'is_active'          => true,
            ],

            // ─── Allowance-only leaves ─────────────────────────────────────
            [
                'code'               => 'D',
                'name'               => 'Dispensary / Medical Leave',
                'pay_type'           => 'allowance_only',
                'allowance_per_day'  => 150.00,   // PKR dispensary subsidy per day
                'half_day_eligible'  => true,
                'max_days_per_week'  => 0,
                'max_days_per_year'  => 12,
                'applicable_to'      => 'all',   // but only dispensary members receive allowance
                'is_active'          => true,
            ],
        ];

        // Add timestamps to every row
        $rows = array_map(fn ($r) => array_merge($r, [
            'created_at' => $now,
            'updated_at' => $now,
        ]), $types);

        DB::table('leave_types')->upsert(
            $rows,
            ['code'],                          // unique key for conflict detection
            [                                  // columns to update on conflict
                'name', 'pay_type', 'allowance_per_day', 'half_day_eligible',
                'max_days_per_week', 'max_days_per_year', 'applicable_to',
                'is_active', 'updated_at',
            ]
        );

        $this->command->info('LeaveTypesSeeder: 15 Bata leave codes seeded.');
    }
}
