<?php

namespace Database\Seeders;

use App\Models\GradeWageRate;
use App\Models\RateCard;
use Illuminate\Database\Seeder;

class GradeWageRatesSeeder extends Seeder
{
    /**
     * Daily wage rates in PKR per grade.
     *
     * Grade values match workers.grade enum: ['A','B','C','D','trainee'].
     * Minimum wage floor (PKR 8,545/month ≈ PKR 1,424/day × 6 days/week):
     *   - Grade D and trainee are below the daily floor and will trigger
     *     the weekly minimum-wage top-up in the payroll engine.
     *   - Grade C and above clear the floor.
     *
     * PKR amounts are demo values — update with actual rates before go-live.
     */
    private const GRADE_WAGES = [
        'trainee' =>  900.00,   // below weekly min-wage floor → top-up triggered
        'D'       => 1200.00,   // below weekly min-wage floor → top-up triggered
        'C'       => 1500.00,   // clears PKR 8,545/month floor (1,500 × 6 = 9,000)
        'B'       => 1800.00,   // mid-range skilled worker
        'A'       => 2200.00,   // senior / highest daily-grade tier
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

        $count = count(self::GRADE_WAGES);
        $this->command->info("Seeded {$count} grade wage rates for rate card {$rateCard->version}.");
    }
}
