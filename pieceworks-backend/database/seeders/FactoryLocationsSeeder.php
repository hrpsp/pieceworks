<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FactoryLocationsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $locations = [
            [
                'name'    => 'Bata Lahore',
                'city'    => 'Lahore',
                'province'=> 'punjab',
                'address' => 'Bata Shoe Factory, G.T. Road, Batapur, Lahore',
            ],
            [
                'name'    => 'Bata Bhalwal',
                'city'    => 'Bhalwal',
                'province'=> 'punjab',
                'address' => 'Bata Shoe Factory, Bhalwal, Sargodha District',
            ],
        ];

        $rows = array_map(fn($l) => array_merge($l, [
            'created_at' => $now,
            'updated_at' => $now,
        ]), $locations);

        DB::table('factory_locations')->insertOrIgnore($rows);

        $this->command->info('Factory locations seeded.');
    }
}
