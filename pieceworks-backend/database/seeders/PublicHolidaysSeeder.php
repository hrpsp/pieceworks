<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PublicHolidaysSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $holidays = [
            // ── Federal Holidays 2026 ──────────────────────────────────────────
            ['name' => 'Kashmir Day',                   'holiday_date' => '2026-02-05', 'province' => 'federal'],
            ['name' => 'Pakistan Day',                  'holiday_date' => '2026-03-23', 'province' => 'federal'],
            ['name' => 'Eid ul-Fitr (Day 1)',           'holiday_date' => '2026-03-20', 'province' => 'federal'],
            ['name' => 'Eid ul-Fitr (Day 2)',           'holiday_date' => '2026-03-21', 'province' => 'federal'],
            ['name' => 'Eid ul-Fitr (Day 3)',           'holiday_date' => '2026-03-22', 'province' => 'federal'],
            ['name' => 'Labour Day',                    'holiday_date' => '2026-05-01', 'province' => 'federal'],
            ['name' => 'Eid ul-Adha (Day 1)',           'holiday_date' => '2026-05-27', 'province' => 'federal'],
            ['name' => 'Eid ul-Adha (Day 2)',           'holiday_date' => '2026-05-28', 'province' => 'federal'],
            ['name' => 'Eid ul-Adha (Day 3)',           'holiday_date' => '2026-05-29', 'province' => 'federal'],
            ['name' => 'Independence Day',              'holiday_date' => '2026-08-14', 'province' => 'federal'],
            ['name' => 'Ashura (9 Muharram)',           'holiday_date' => '2026-07-15', 'province' => 'federal'],
            ['name' => 'Ashura (10 Muharram)',          'holiday_date' => '2026-07-16', 'province' => 'federal'],
            ['name' => "Eid Milad-un-Nabi (Prophet's Birthday)", 'holiday_date' => '2026-09-14', 'province' => 'federal'],
            ['name' => 'Iqbal Day',                     'holiday_date' => '2026-11-09', 'province' => 'federal'],
            ['name' => 'Quaid-e-Azam Day / Christmas', 'holiday_date' => '2026-12-25', 'province' => 'federal'],

            // ── Punjab Provincial Holidays 2026 ───────────────────────────────
            ['name' => 'Punjab Culture Day',            'holiday_date' => '2026-03-14', 'province' => 'punjab'],
        ];

        $rows = array_map(fn($h) => array_merge($h, [
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]), $holidays);

        DB::table('public_holidays')->insertOrIgnore($rows);

        $this->command->info('Public holidays seeded (' . count($rows) . ' records).');
    }
}
