<?php

namespace Database\Seeders;

use App\Models\RateCard;
use App\Models\RateCardEntry;
use App\Models\User;
use Illuminate\Database\Seeder;

class RateCardSeeder extends Seeder
{
    public function run(): void
    {
        // Only seed if no active rate card exists
        if (RateCard::where('is_active', true)->exists()) {
            $this->command->info('Rate card already seeded, skipping.');
            return;
        }

        $admin = User::first();

        $rateCard = RateCard::create([
            'version'        => 'v4',
            'effective_date' => '2026-02-01',
            'is_active'      => true,
            'approved_by'    => $admin?->id,
            'notes'          => 'Rate card v4 — effective Feb 1, 2026',
        ]);

        // Matrix: [task => [tier => [junior_rate, senior_rate]]]
        $matrix = [
            'Cutting'    => ['simple' => [8, 10], 'standard' => [10, 13], 'complex' => [13, 16], 'premium' => [16, 20]],
            'Stitching'  => ['simple' => [22, 28], 'standard' => [28, 35], 'complex' => [35, 44], 'premium' => [42, 52]],
            'Lasting'    => ['simple' => [18, 22], 'standard' => [22, 28], 'complex' => [28, 35], 'premium' => [34, 42]],
            'Sole Press' => ['simple' => [12, 15], 'standard' => [15, 18], 'complex' => [18, 22], 'premium' => [22, 28]],
            'Finishing'  => ['simple' => [10, 12], 'standard' => [12, 15], 'complex' => [15, 18], 'premium' => [18, 22]],
            'Packing'    => ['simple' => [6, 8], 'standard' => [8, 10], 'complex' => [10, 12], 'premium' => [12, 15]],
        ];

        $grades = ['junior' => 0, 'senior' => 1];
        $entries = [];

        foreach ($matrix as $task => $tiers) {
            foreach ($tiers as $tier => $rates) {
                foreach ($grades as $grade => $idx) {
                    $entries[] = [
                        'rate_card_id'    => $rateCard->id,
                        'task'            => $task,
                        'complexity_tier' => $tier,
                        'worker_grade'    => $grade,
                        'rate_pkr'        => $rates[$idx],
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ];
                }
            }
        }

        RateCardEntry::insert($entries);
        $this->command->info('Rate card v4 seeded with ' . count($entries) . ' entries.');
    }
}
