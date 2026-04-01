<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DeductionTypesSeeder::class,
            FactoryLocationsSeeder::class,
            PublicHolidaysSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
