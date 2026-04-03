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
            RateCardSeeder::class,
            GradeWageRatesSeeder::class,  // CR-001: grade daily wages for daily_grade + hybrid models
            DemoDataSeeder::class,        // demo lines, workers, production units, records
        ]);
    }
}
