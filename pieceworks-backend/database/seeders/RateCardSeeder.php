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
            'notes'          => 'Rate card v4 — canonical grades A/B/C/D, effective Feb 1 2026',
        ]);

        // Grade key:  A = highly skilled  · B = mid-level  · C = entry-level  · D = unskilled/basic
        // Tier key:   simple < standard < complex < premium
        // Rate (PKR per pair produced) — trainee workers are daily-wage only, no piece rate entry needed.
        //
        // Matrix: [task => [tier => [A, B, C, D]]]
        $matrix = [
            'Cutting'    => [
                'simple'   => [12,  9,  7,  5],
                'standard' => [18, 14, 10,  8],
                'complex'  => [22, 17, 13, 10],
                'premium'  => [28, 22, 17, 13],
            ],
            'Stitching'  => [
                'simple'   => [28, 22, 18, 14],
                'standard' => [38, 30, 24, 19],
                'complex'  => [48, 38, 30, 24],
                'premium'  => [58, 46, 37, 29],
            ],
            'Lasting'    => [
                'simple'   => [22, 17, 14, 11],
                'standard' => [28, 22, 17, 14],
                'complex'  => [35, 28, 22, 17],
                'premium'  => [42, 34, 27, 21],
            ],
            'Sole Press' => [
                'simple'   => [15, 12, 10,  8],
                'standard' => [22, 17, 13, 10],
                'complex'  => [28, 22, 17, 13],
                'premium'  => [34, 27, 21, 17],
            ],
            'Finishing'  => [
                'simple'   => [12,  9,  7,  5],
                'standard' => [16, 13,  9,  7],
                'complex'  => [20, 16, 12,  9],
                'premium'  => [24, 19, 15, 11],
            ],
            'Packing'    => [
                'simple'   => [ 8,  6,  5,  4],
                'standard' => [12,  9,  7,  6],
                'complex'  => [15, 12,  9,  7],
                'premium'  => [18, 14, 11,  9],
            ],
        ];

        $grades = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
        $entries = [];
        $now     = now();

        foreach ($matrix as $task => $tiers) {
            foreach ($tiers as $tier => $rates) {
                foreach ($grades as $grade => $idx) {
                    $entries[] = [
                        'rate_card_id'    => $rateCard->id,
                        'task'            => $task,
                        'complexity_tier' => $tier,
                        'worker_grade'    => $grade,
                        'rate_pkr'        => $rates[$idx],
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ];
                }
            }
        }

        RateCardEntry::insert($entries);
        $this->command->info('Rate card v4 seeded with ' . count($entries) . ' entries (grades A/B/C/D × 4 tiers × 6 tasks).');
    }
}
