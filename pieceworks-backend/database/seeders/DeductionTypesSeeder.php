<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeductionTypesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $types = [
            [
                'name'              => 'Rejection Penalty',
                'code'              => 'rejection_penalty',
                'calculation_type'  => 'pairs_based',
                'requires_approval' => false,
                'max_per_week'      => null,
                'is_active'         => true,
            ],
            [
                'name'              => 'Advance Recovery',
                'code'              => 'advance_recovery',
                'calculation_type'  => 'flat',
                'requires_approval' => false,
                'max_per_week'      => null,
                'is_active'         => true,
            ],
            [
                'name'              => 'Loan EMI',
                'code'              => 'loan_emi',
                'calculation_type'  => 'flat',
                'requires_approval' => false,
                'max_per_week'      => null,
                'is_active'         => true,
            ],
            [
                'name'              => 'Material Wastage',
                'code'              => 'material_wastage',
                'calculation_type'  => 'flat',
                'requires_approval' => true,
                'max_per_week'      => 500.00,
                'is_active'         => true,
            ],
            [
                'name'              => 'Equipment Damage',
                'code'              => 'equipment_damage',
                'calculation_type'  => 'flat',
                'requires_approval' => true,
                'max_per_week'      => 1000.00,
                'is_active'         => true,
            ],
            [
                'name'              => 'Miscellaneous',
                'code'              => 'misc',
                'calculation_type'  => 'flat',
                'requires_approval' => true,
                'max_per_week'      => null,
                'is_active'         => true,
            ],
        ];

        $rows = array_map(fn($t) => array_merge($t, [
            'created_at' => $now,
            'updated_at' => $now,
        ]), $types);

        DB::table('deduction_types')->insertOrIgnore($rows);

        $this->command->info('Deduction types seeded.');
    }
}
