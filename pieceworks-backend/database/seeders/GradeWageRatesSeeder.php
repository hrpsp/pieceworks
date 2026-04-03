<?php

namespace Database\Seeders;

use App\Models\GradeWageRate;
use App\Models\RateCard;
use Illuminate\Database\Seeder;

class GradeWageRatesSeeder extends Seeder
{
    /**
     * Daily wage rates in PKR per grade, effective against the active rate card.
     */
    private const GRADE_WAGES = [
        'grade_1'  =>  800,
        'grade_2'  =>  900,
        'grade_3'  => 1000,
        'grade_4'  => 1100,
        'grade_5'  => 1250,
        'grade_6'  => 1400,
        'grade_7'  => 1600,
        'grade_8'  => 1800,
        'grade_9'  => 2000,
        'grade_10' => 2400,
    ];

    public function run(): void
    {
        $rateCard = RateCard::where('is_active', true)->firstOrFail();

        foreach (self::GRADE_WAGES as $grade => $dailyWage) {
            GradeWageRate::updateOrCreate(
                [
                    'rate_card_id' => $rateCard->id,
                    'grade'        => $grade,
                ],
                [
                    'daily_wage_pkr' => $dailyWage,
                ]
            );
        }

        $this->command->info("Seeded " . count(self::GRADE_WAGES) . " grade wage rates for rate card {$rateCard->version}.");
    }
}
