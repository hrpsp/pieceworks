<?php

namespace Database\Seeders;

use App\Models\Contractor;
use App\Models\Line;
use App\Models\PayrollException;
use App\Models\ProductionRecord;
use App\Models\RateCard;
use App\Models\RateCardEntry;
use App\Models\StyleSku;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DemoDataSeeder
 *
 * Seeds a realistic week of factory data:
 *   - 3 contractors, 8 workers spread across them
 *   - 2 production lines
 *   - Active rate card v4 with 4 tasks × 3 grades × 2 tiers
 *   - 4 style SKUs (standard, complex, premium mix)
 *   - Mon–Sat production records (week 2026-W14)
 *   - One worker below minimum wage floor (triggers top-up)
 *   - One worker with a ghost-risk flag
 *   - A payroll run in 'open' state with 2 unresolved exceptions
 *
 * Usage:
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    // ISO week used throughout the demo data
    private const WEEK_REF  = '2026-W14';
    private const START_DATE = '2026-03-30'; // Monday
    private const END_DATE   = '2026-04-04'; // Saturday

    public function run(): void
    {
        $this->command->info('Seeding demo data for ' . self::WEEK_REF . ' …');

        $this->seedLines();
        $this->seedStyleSkus();
        $this->seedRateCard();
        [$c1, $c2, $c3] = $this->seedContractors();
        $workers = $this->seedWorkers($c1, $c2, $c3);
        $this->seedProductionRecords($workers);
        $this->seedPayrollRun($workers);

        $this->command->info('Demo data seeded successfully.');
        $this->command->table(
            ['Credential', 'Value'],
            [
                ['Admin login',    'admin@pieceworks.pk'],
                ['Password',       'password'],
                ['Week reference', self::WEEK_REF],
                ['Workers seeded', count($workers)],
            ]
        );
    }

    // ── Lines ─────────────────────────────────────────────────────────────────

    private function seedLines(): void
    {
        Line::firstOrCreate(['name' => 'Line A'], [
            'factory_location'    => 'Bata Lahore',
            'default_shift'       => 'morning',
            'capacity_pairs_day'  => 500,
        ]);

        Line::firstOrCreate(['name' => 'Line B'], [
            'factory_location'    => 'Bata Lahore',
            'default_shift'       => 'morning',
            'capacity_pairs_day'  => 480,
        ]);

        $this->command->info('  Lines: 2 seeded');
    }

    // ── Style SKUs ────────────────────────────────────────────────────────────

    private function seedStyleSkus(): void
    {
        $skus = [
            ['style_code' => 'BT-101', 'style_name' => 'Bata Power Classic',   'complexity_tier' => 'standard'],
            ['style_code' => 'BT-204', 'style_name' => 'Comfit Leather Formal','complexity_tier' => 'complex' ],
            ['style_code' => 'BT-318', 'style_name' => 'North Star Runner',    'complexity_tier' => 'standard'],
            ['style_code' => 'BT-422', 'style_name' => 'Weinbrenner Trekker',  'complexity_tier' => 'complex' ],
        ];

        foreach ($skus as $sku) {
            StyleSku::firstOrCreate(['style_code' => $sku['style_code']], $sku);
        }

        $this->command->info('  Style SKUs: ' . count($skus) . ' seeded');
    }

    // ── Rate card ─────────────────────────────────────────────────────────────

    private function seedRateCard(): void
    {
        // Deactivate any existing active cards
        RateCard::where('is_active', true)->update(['is_active' => false]);

        $card = RateCard::firstOrCreate(
            ['version' => '4.0'],
            [
                'effective_date'    => '2026-01-01',
                'is_active'         => true,
                'training_rate_pct' => 70.00,
                'notes'             => 'Demo rate card — seeded by DemoDataSeeder',
            ]
        );

        // task × grade × tier → rate_pkr
        $entries = [
            // Lasting
            ['task' => 'Lasting',      'worker_grade' => 'C',   'complexity_tier' => 'standard', 'rate_pkr' =>  42.00],
            ['task' => 'Lasting',      'worker_grade' => 'C',   'complexity_tier' => 'complex',  'rate_pkr' =>  55.00],
            ['task' => 'Lasting',      'worker_grade' => 'B', 'complexity_tier' => 'standard', 'rate_pkr' =>  52.00],
            ['task' => 'Lasting',      'worker_grade' => 'B', 'complexity_tier' => 'complex',  'rate_pkr' =>  67.00],
            ['task' => 'Lasting',      'worker_grade' => 'A',   'complexity_tier' => 'standard', 'rate_pkr' =>  62.00],
            ['task' => 'Lasting',      'worker_grade' => 'A',   'complexity_tier' => 'complex',  'rate_pkr' =>  88.00],
            // Stitching
            ['task' => 'Stitching',    'worker_grade' => 'C',   'complexity_tier' => 'standard', 'rate_pkr' =>  38.00],
            ['task' => 'Stitching',    'worker_grade' => 'C',   'complexity_tier' => 'complex',  'rate_pkr' =>  50.00],
            ['task' => 'Stitching',    'worker_grade' => 'B', 'complexity_tier' => 'standard', 'rate_pkr' =>  48.00],
            ['task' => 'Stitching',    'worker_grade' => 'B', 'complexity_tier' => 'complex',  'rate_pkr' =>  63.00],
            ['task' => 'Stitching',    'worker_grade' => 'A',   'complexity_tier' => 'standard', 'rate_pkr' =>  58.00],
            ['task' => 'Stitching',    'worker_grade' => 'A',   'complexity_tier' => 'complex',  'rate_pkr' =>  82.00],
            // Sole Pressing
            ['task' => 'Sole Pressing','worker_grade' => 'C',   'complexity_tier' => 'standard', 'rate_pkr' =>  35.00],
            ['task' => 'Sole Pressing','worker_grade' => 'B', 'complexity_tier' => 'standard', 'rate_pkr' =>  45.00],
            ['task' => 'Sole Pressing','worker_grade' => 'A',   'complexity_tier' => 'standard', 'rate_pkr' =>  55.00],
            ['task' => 'Sole Pressing','worker_grade' => 'A',   'complexity_tier' => 'complex',  'rate_pkr' =>  75.00],
            // Finishing
            ['task' => 'Finishing',    'worker_grade' => 'C',   'complexity_tier' => 'standard', 'rate_pkr' =>  30.00],
            ['task' => 'Finishing',    'worker_grade' => 'B', 'complexity_tier' => 'standard', 'rate_pkr' =>  40.00],
            ['task' => 'Finishing',    'worker_grade' => 'B', 'complexity_tier' => 'complex',  'rate_pkr' =>  53.00],
            ['task' => 'Finishing',    'worker_grade' => 'A',   'complexity_tier' => 'standard', 'rate_pkr' =>  50.00],
            ['task' => 'Finishing',    'worker_grade' => 'A',   'complexity_tier' => 'complex',  'rate_pkr' =>  70.00],
        ];

        foreach ($entries as $e) {
            RateCardEntry::firstOrCreate(
                [
                    'rate_card_id'    => $card->id,
                    'task'            => $e['task'],
                    'worker_grade'    => $e['worker_grade'],
                    'complexity_tier' => $e['complexity_tier'],
                ],
                ['rate_pkr' => $e['rate_pkr']]
            );
        }

        $this->command->info('  Rate card v4.0: ' . count($entries) . ' entries seeded');
    }

    // ── Contractors ───────────────────────────────────────────────────────────

    private function seedContractors(): array
    {
        $c1 = Contractor::firstOrCreate(['ntn_cnic' => '0001234-5'], [
            'name'           => 'Khan Labour Services',
            'contact_person' => 'Imran Khan',
            'phone'          => '0300-1234567',
            'contract_start' => '2024-01-01',
            'contract_end'   => null,
            'payment_cycle'  => 'weekly',
            'portal_access'  => false,
            'status'         => 'active',
        ]);

        $c2 = Contractor::firstOrCreate(['ntn_cnic' => '0002345-6'], [
            'name'           => 'Raza Manpower Solutions',
            'contact_person' => 'Ali Raza',
            'phone'          => '0321-9876543',
            'contract_start' => '2023-06-01',
            'contract_end'   => null,
            'payment_cycle'  => 'weekly',
            'portal_access'  => false,
            'status'         => 'active',
        ]);

        $c3 = Contractor::firstOrCreate(['ntn_cnic' => '0003456-7'], [
            'name'           => 'Premier Skilled Workers',
            'contact_person' => 'Tariq Mehmood',
            'phone'          => '0333-5556677',
            'contract_start' => '2024-07-15',
            'contract_end'   => null,
            'payment_cycle'  => 'biweekly',
            'portal_access'  => false,
            'status'         => 'active',
        ]);

        $this->command->info('  Contractors: 3 seeded');
        return [$c1, $c2, $c3];
    }

    // ── Workers ───────────────────────────────────────────────────────────────

    private function seedWorkers(Contractor $c1, Contractor $c2, Contractor $c3): array
    {
        $now = now()->subMonths(6);  // default join date

        $defs = [
            // Khan Labour Services (c1) — 3 workers
            [
                'contractor_id'  => $c1->id,
                'name'           => 'Muhammad Asif',
                'cnic'           => '35201-1234567-1',
                'worker_type'    => 'contractual',
                'grade' => 'A',
                'default_shift'  => 'morning',
                'payment_method' => 'easypaisa',
                'payment_number' => '0300-1111111',
                'whatsapp'       => '0300-1111111',
                'eobi_number'    => 'EOBI-001-2024',
                'join_date'      => '2023-03-15',
                'status'         => 'active',
            ],
            [
                'contractor_id'  => $c1->id,
                'name'           => 'Sajjad Hussain',
                'cnic'           => '35201-2345678-2',
                'worker_type'    => 'contractual',
                'grade' => 'B',
                'default_shift'  => 'morning',
                'payment_method' => 'easypaisa',
                'payment_number' => '0312-2222222',
                'whatsapp'       => '0312-2222222',
                'eobi_number'    => 'EOBI-002-2024',
                'join_date'      => '2024-01-10',
                'status'         => 'active',
            ],
            [
                'contractor_id'  => $c1->id,
                'name'           => 'Nadia Bibi',
                'cnic'           => '35202-3456789-0',
                'worker_type'    => 'contractual',
                'grade' => 'C',
                'default_shift'  => 'morning',
                'payment_method' => 'jazzcash',
                'payment_number' => '0345-3333333',
                'whatsapp'       => '0345-3333333',
                'eobi_number'    => null,
                'join_date'      => '2026-01-05',  // recent — IRRA threshold approaching
                'status'         => 'active',
            ],
            // Raza Manpower (c2) — 3 workers
            [
                'contractor_id'  => $c2->id,
                'name'           => 'Tariq Ahmed',
                'cnic'           => '35401-4567890-3',
                'worker_type'    => 'contractual',
                'grade' => 'B',
                'default_shift'  => 'morning',
                'payment_method' => 'bank',
                'payment_number' => 'HBL-001234567',
                'whatsapp'       => '0321-4444444',
                'eobi_number'    => 'EOBI-003-2024',
                'join_date'      => '2023-08-20',
                'status'         => 'active',
            ],
            [
                'contractor_id'  => $c2->id,
                'name'           => 'Shafiq Ur Rahman',
                'cnic'           => '35401-5678901-4',
                'worker_type'    => 'contractual',
                'grade' => 'A',
                'default_shift'  => 'morning',
                'payment_method' => 'easypaisa',
                'payment_number' => '0333-5555555',
                'whatsapp'       => '0333-5555555',
                'eobi_number'    => 'EOBI-004-2024',
                'join_date'      => '2022-11-01',
                'status'         => 'active',
            ],
            [
                'contractor_id'  => $c2->id,
                'name'           => 'Zainab Fatima',
                'cnic'           => '35202-6789012-9',
                'worker_type'    => 'contractual',
                'grade' => 'C',
                'default_shift'  => 'morning',
                'payment_method' => 'jazzcash',
                'payment_number' => '0300-6666666',
                'whatsapp'       => '0300-6666666',
                'eobi_number'    => null,
                'join_date'      => '2025-10-01',
                'status'         => 'active',
                // Low daily output → triggers min-wage top-up exception
            ],
            // Premier Skilled Workers (c3) — 2 workers
            [
                'contractor_id'  => $c3->id,
                'name'           => 'Khalid Mehmood',
                'cnic'           => '35501-7890123-5',
                'worker_type'    => 'contractual',
                'grade' => 'B',
                'default_shift'  => 'morning',
                'payment_method' => 'bank',
                'payment_number' => 'UBL-009876543',
                'whatsapp'       => '0321-7777777',
                'eobi_number'    => 'EOBI-005-2024',
                'join_date'      => '2024-04-10',
                'status'         => 'active',
            ],
            [
                'contractor_id'  => $c3->id,
                'name'           => 'Farhan Iqbal',
                'cnic'           => '35501-8901234-6',
                'worker_type'    => 'contractual',
                'grade' => 'C',
                'default_shift'  => 'morning',
                'payment_method' => 'easypaisa',
                'payment_number' => '0300-8888888',
                'whatsapp'       => '0300-8888888',
                'eobi_number'    => null,
                'join_date'      => '2025-12-15',  // ghost-risk candidate
                'status'         => 'active',
            ],
        ];

        $workers = [];
        foreach ($defs as $d) {
            $workers[] = Worker::firstOrCreate(['cnic' => $d['cnic']], $d);
        }

        $this->command->info('  Workers: ' . count($workers) . ' seeded');
        return $workers;
    }

    // ── Production records ────────────────────────────────────────────────────

    private function seedProductionRecords(array $workers): void
    {
        $lineA = Line::where('name', 'Line A')->first();
        $lineB = Line::where('name', 'Line B')->first();
        $card  = RateCard::where('version', '4.0')->first();

        // task → [grade, tier] → rate entry
        $rateMap = RateCardEntry::where('rate_card_id', $card->id)
            ->get()
            ->groupBy(fn($e) => "{$e->task}|{$e->worker_grade}|{$e->complexity_tier}");

        // Work days: Mon 30 Mar – Sat 4 Apr 2026
        $workDays = collect(range(0, 5))->map(fn($i) =>
            Carbon::parse(self::START_DATE)->addDays($i)->toDateString()
        );

        /** @var Worker[] $workers */
        [$asif, $sajjad, $nadia, $tariq, $shafiq, $zainab, $khalid, $farhan] = $workers;

        // Pairs-per-day schedules — indexed [worker_index][day_index]
        // Low numbers for Zainab (min-wage trigger), normal for others
        // Farhan has missing day 4 (ghost flag scenario)
        $schedules = [
            // [worker, line,  task,           tier,      pairs/day × 6 days]
            [$asif,   $lineA, 'Lasting',      'standard', [95, 98, 100, 102, 97, 96]],
            [$sajjad, $lineA, 'Stitching',    'standard', [80, 82, 78,  85,  81, 83]],
            [$nadia,  $lineA, 'Finishing',    'standard', [60, 62, 58,  65,  61, 63]],
            [$tariq,  $lineB, 'Stitching',    'complex',  [72, 74, 70,  76,  73, 71]],
            [$shafiq, $lineB, 'Lasting',      'standard', [88, 90, 86,  93,  89, 91]],
            [$zainab, $lineB, 'Finishing',    'standard', [18, 22, 20,  19,  17, 21]], // below floor
            [$khalid, $lineA, 'Sole Pressing','standard', [70, 72, 68,  75,  71, 69]],
            [$farhan, $lineB, 'Finishing',    'standard', [40, 42, 38,  null, 39, 41]], // null = absent day 3
        ];

        $inserted = 0;
        foreach ($schedules as [$worker, $line, $task, $tier, $pairsPerDay]) {
            foreach ($workDays as $idx => $date) {
                $pairs = $pairsPerDay[$idx] ?? null;
                if ($pairs === null) continue; // absent day

                $entryKey = "{$task}|{$worker->grade}|{$tier}";
                $entry    = $rateMap->get($entryKey)?->first();

                $rate     = $entry ? (float) $entry->rate_pkr : 0.0;
                $earnings = round($pairs * $rate, 2);

                // Farhan day 5 — mark ghost risk
                $ghostRisk = ($worker->id === $farhan->id && $idx === 4)
                    ? 'medium'
                    : 'none';

                ProductionRecord::firstOrCreate(
                    [
                        'worker_id' => $worker->id,
                        'work_date' => $date,
                        'shift'     => 'morning',
                        'task'      => $task,
                    ],
                    [
                        'line_id'             => $line->id,
                        'rate_card_entry_id'  => $entry?->id,
                        'style_sku_id'        => null,
                        'pairs_produced'      => $pairs,
                        'rate_amount'         => $rate,
                        'gross_earnings'      => $earnings,
                        'source_tag' => 'manual_supervisor',
                        'validation_status'   => 'validated',
                        'is_locked'           => false,
                        'ghost_risk_level'    => $ghostRisk,
                        'billing_contractor_id' => $worker->contractor_id,
                    ]
                );
                $inserted++;
            }
        }

        $this->command->info("  Production records: {$inserted} seeded");
    }

    // ── Payroll run + exceptions ──────────────────────────────────────────────

    private function seedPayrollRun(array $workers): void
    {
        /** @var Worker[] $workers */
        [, , , , , $zainab, , $farhan] = $workers;

        // Compute rough totals from production records
        $gross = ProductionRecord::whereDate('work_date', '>=', self::START_DATE)
            ->whereDate('work_date', '<=', self::END_DATE)
            ->sum('gross_earnings');

        $run = WeeklyPayrollRun::firstOrCreate(
            ['week_ref' => self::WEEK_REF],
            [
                'start_date'       => self::START_DATE,
                'end_date'         => self::END_DATE,
                'status'           => 'open',
                'total_gross'      => $gross,
                'total_topups'     => 1200.00,  // Zainab's min-wage top-up
                'total_deductions' => 0.00,
                'total_net'        => $gross + 1200.00,
                'locked_at'        => null,
                'locked_by'        => null,
                'released_at'      => null,
                'released_by'      => null,
            ]
        );

        // Exception 1: Zainab below minimum wage
        PayrollException::firstOrCreate(
            [
                'payroll_run_id' => $run->id,
                'worker_id'      => $zainab->id,
                'exception_type' => 'min_wage_shortfall',
            ],
            [
                'worker_weekly_payroll_id' => null,
                'description'   => "Zainab Fatima's gross earnings (PKR 2,464) fall below the minimum wage floor (PKR 3,577/week for Punjab). A top-up of PKR 1,113 is required.",
                'amount'        => 1113.00,
                'resolved_at'   => null,
                'resolved_by'   => null,
                'resolution_note' => null,
            ]
        );

        // Exception 2: Farhan ghost-risk flag
        PayrollException::firstOrCreate(
            [
                'payroll_run_id' => $run->id,
                'worker_id'      => $farhan->id,
                'exception_type' => 'disputed_records',
            ],
            [
                'worker_weekly_payroll_id' => null,
                'description'   => "Farhan Iqbal has a medium ghost-risk flag on 2026-04-03 (Thursday). Attendance should be verified before payroll is locked.",
                'amount'        => null,
                'resolved_at'   => null,
                'resolved_by'   => null,
                'resolution_note' => null,
            ]
        );

        $this->command->info('  Payroll run: ' . self::WEEK_REF . ' (open) with 2 exceptions seeded');
    }
}
