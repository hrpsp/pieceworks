<?php

namespace Database\Seeders;

use App\Models\Contractor;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * CR-009 — BataDemoSeeder
 *
 * Seeds a complete, realistic Bata Pakistan demo dataset for client previews
 * and UAT testing. All data is scoped to week 2026-W15 (Apr 6–11, 2026).
 *
 * Dataset summary:
 *   • 1  factory location  (Bata Pakistan – Lahore Factory)
 *   • 1  contractor        (Khan Labour Services, TOR 15 %)
 *   • 4  production lines  (Cutting · Stitching · Lasting · Finishing)
 *   • 9  production units  (2–3 per line)
 *   • 20 workers           (grades A/B/C/D, shifts GA/E1/E2/E3)
 *   • 20 compliance records (EOBI + PESSI)
 *   • 120 production records (20 workers × 6 days)
 *   • 120 attendance records
 *   • 1  payroll run       (2026-W15)
 *   • 20 worker_weekly_payroll rows
 *   • 2  payroll exceptions
 *   • 1  contractor settlement
 *   • 4  demo user accounts
 *
 * Run order (DatabaseSeeder must call this after LeaveTypesSeeder):
 *   php artisan db:seed --class=BataDemoSeeder
 *
 * Reset:
 *   php artisan demo:bata-reset
 */
class BataDemoSeeder extends Seeder
{
    // ── Constants ─────────────────────────────────────────────────────────────

    private const WEEK_REF    = '2026-W15';
    private const WEEK_START  = '2026-04-06';    // Monday
    private const WEEK_END    = '2026-04-11';    // Saturday
    private const WORK_DATES  = [
        '2026-04-06',  // Mon
        '2026-04-07',  // Tue
        '2026-04-08',  // Wed  (OT day for Usman & Hamid)
        '2026-04-09',  // Thu
        '2026-04-10',  // Fri
        '2026-04-11',  // Sat
    ];

    private const MIN_WEEKLY_WAGE = 8_545.00;

    // ── Entry point ───────────────────────────────────────────────────────────

    public function run(): void
    {
        $this->command->info('BataDemoSeeder: starting …');

        DB::transaction(function () {
            $location   = $this->seedFactoryLocation();
            $contractor = $this->seedContractor();
            $users      = $this->seedUsers($contractor);
            $lines      = $this->seedLines($contractor, $users['supervisor']);
            $units      = $this->seedProductionUnits($lines, $contractor);
            $rceMap     = $this->loadRateCardEntries();
            $workers    = $this->seedWorkers($contractor, $lines);
            $this->seedCompliance($workers);
            $this->seedProductionRecords($workers, $lines, $rceMap, $users['supervisor']);
            $this->seedAttendanceRecords($workers);
            $payrollRun = $this->seedPayrollRun();
            $this->seedWorkerPayrolls($workers, $payrollRun, $rceMap);
            $this->seedPayrollExceptions($workers, $payrollRun);
            $this->seedContractorSettlement($contractor, $payrollRun);
            $this->seedDemoCredentialsFile($users);
        });

        $this->command->info('BataDemoSeeder: complete.');
    }

    // ── 1. Factory location ────────────────────────────────────────────────────

    private function seedFactoryLocation(): object
    {
        $id = DB::table('factory_locations')->insertGetId([
            'name'       => 'Bata Pakistan – Lahore Factory',
            'city'       => 'Lahore',
            'province'   => 'Punjab',
            'address'    => '3-Industrial Estate, Kot Lakhpat, Lahore, Punjab 54760',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info('  ✓ Factory location created');
        return (object) ['id' => $id];
    }

    // ── 2. Contractor ─────────────────────────────────────────────────────────

    private function seedContractor(): Contractor
    {
        $c = Contractor::firstOrCreate(
            ['ntn_cnic' => 'BATA-KLS-001'],
            [
                'name'           => 'Khan Labour Services',
                'contact_person' => 'Imran Khan',
                'phone'          => '0300-1234567',
                'whatsapp'       => '0300-1234567',
                'contract_start' => '2024-01-01',
                'contract_end'   => null,
                'payment_cycle'  => 'weekly',
                'portal_access'  => true,
                'tor_rate_pct'   => 15.00,
                'status'         => 'active',
            ]
        );
        $c->update(['tor_rate_pct' => 15.00]);

        $this->command->info('  ✓ Contractor: Khan Labour Services (TOR 15 %)');
        return $c;
    }

    // ── 3. Demo users ─────────────────────────────────────────────────────────

    private function seedUsers(Contractor $contractor): array
    {
        $password = Hash::make('Password@1');

        $manager = $this->upsertUser([
            'name'     => 'Adnan Payroll Manager',
            'email'    => 'payroll.manager@bata.demo',
            'role'     => 'payroll_manager',
            'password' => $password,
        ]);

        $supervisor = $this->upsertUser([
            'name'     => 'Kamran Supervisor',
            'email'    => 'supervisor@bata.demo',
            'role'     => 'supervisor',
            'password' => $password,
        ]);

        $contractorUser = $this->upsertUser([
            'name'        => 'Khan Portal',
            'email'       => 'contractor@bata.demo',
            'role'        => 'supervisor',   // contractor portal access uses supervisor role
            'password'    => $password,
            'contractor_id' => $contractor->id,
        ]);

        $qcInspector = $this->upsertUser([
            'name'     => 'Sajida QC Inspector',
            'email'    => 'qc.inspector@bata.demo',
            'role'     => 'supervisor',
            'password' => $password,
        ]);

        $this->command->info('  ✓ 4 demo users created');

        return [
            'manager'    => $manager,
            'supervisor' => $supervisor,
            'contractor' => $contractorUser,
            'qc'         => $qcInspector,
        ];
    }

    private function upsertUser(array $data): object
    {
        $existing = DB::table('users')->where('email', $data['email'])->first();
        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update(array_merge($data, [
                'updated_at' => now(),
            ]));
            return DB::table('users')->where('id', $existing->id)->first();
        }

        $id = DB::table('users')->insertGetId(array_merge($data, [
            'email_verified_at' => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]));
        return DB::table('users')->find($id);
    }

    // ── 4. Production lines ───────────────────────────────────────────────────

    private function seedLines(Contractor $contractor, object $supervisor): array
    {
        $defs = [
            ['code' => 'LA', 'name' => 'Line A – Cutting',          'shift' => 'GA', 'cap' => 600],
            ['code' => 'LB', 'name' => 'Line B – Stitching',        'shift' => 'E2', 'cap' => 550],
            ['code' => 'LC', 'name' => 'Line C – Lasting/Assembly', 'shift' => 'E1', 'cap' => 500],
            ['code' => 'LD', 'name' => 'Line D – Finishing/Packing','shift' => 'GA', 'cap' => 480],
        ];

        $lines = [];
        foreach ($defs as $def) {
            $existing = DB::table('lines')->where('name', $def['name'])->first();
            if ($existing) {
                $lines[$def['code']] = $existing;
                continue;
            }
            $id = DB::table('lines')->insertGetId([
                'name'                  => $def['name'],
                'factory_location'      => 'Bata Pakistan – Lahore Factory',
                'default_shift'         => $def['shift'],
                'supervisor_id'         => $supervisor->id,
                'default_contractor_id' => $contractor->id,
                'capacity_pairs_day'    => $def['cap'],
                'status'                => 'active',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
            $lines[$def['code']] = DB::table('lines')->find($id);
        }

        $this->command->info('  ✓ 4 production lines');
        return $lines;
    }

    // ── 5. Production units ───────────────────────────────────────────────────

    private function seedProductionUnits(array $lines, Contractor $contractor): array
    {
        $defs = [
            ['line' => 'LA', 'name' => 'PA-1 Clicking / Die Cutting', 'op' => 'Cutting',   'cap' => 3],
            ['line' => 'LA', 'name' => 'PA-2 Sole Cutting',           'op' => 'Cutting',   'cap' => 2],
            ['line' => 'LB', 'name' => 'PB-1 Upper Stitching',        'op' => 'Stitching', 'cap' => 3],
            ['line' => 'LB', 'name' => 'PB-2 Lining Attachment',      'op' => 'Stitching', 'cap' => 2],
            ['line' => 'LB', 'name' => 'PB-3 Thread Finishing',       'op' => 'Stitching', 'cap' => 2],
            ['line' => 'LC', 'name' => 'PC-1 Lasting',                'op' => 'Lasting',   'cap' => 3],
            ['line' => 'LC', 'name' => 'PC-2 Cementing / Assembly',   'op' => 'Lasting',   'cap' => 2],
            ['line' => 'LD', 'name' => 'PD-1 Finishing',              'op' => 'Finishing', 'cap' => 3],
            ['line' => 'LD', 'name' => 'PD-2 Packing',                'op' => 'Packing',   'cap' => 2],
        ];

        $units = [];
        foreach ($defs as $def) {
            $lineId   = $lines[$def['line']]->id;
            $existing = DB::table('production_units')
                ->where('name', $def['name'])
                ->where('line_id', $lineId)
                ->first();

            if ($existing) {
                $units[] = $existing;
                continue;
            }

            $id = DB::table('production_units')->insertGetId([
                'name'                  => $def['name'],
                'line_id'               => $lineId,
                'operation'             => $def['op'],
                'wage_model'            => 'per_pair',
                'default_contractor_id' => $contractor->id,
                'capacity_workers'      => $def['cap'],
                'status'                => 'active',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
            $units[] = DB::table('production_units')->find($id);
        }

        $this->command->info('  ✓ 9 production units');
        return $units;
    }

    // ── 6. Rate card entries ──────────────────────────────────────────────────
    //    Returns a lookup map: [task][grade][tier] → rate_card_entry row

    private function loadRateCardEntries(): array
    {
        $activeCard = DB::table('rate_cards')->where('is_active', true)->first();
        if (! $activeCard) {
            $this->command->warn('  ⚠ No active rate card found — production records will have no rate_card_entry_id');
            return [];
        }

        $entries = DB::table('rate_card_entries')
            ->where('rate_card_id', $activeCard->id)
            ->get();

        $map = [];
        foreach ($entries as $entry) {
            $map[$entry->task][$entry->worker_grade][$entry->complexity_tier] = $entry;
        }

        $this->command->info("  ✓ Rate card loaded (id={$activeCard->id}, v{$activeCard->version})");
        return $map;
    }

    // ── 7. Workers ────────────────────────────────────────────────────────────

    private function seedWorkers(Contractor $contractor, array $lines): array
    {
        /*
         * 20 workers — 5 per line, mixed grades A/B/C/D, shifts GA/E1/E2/E3.
         * Usman Farooq (#1) and Hamid Ali (#2) are on E2 (Line B) for OT demo.
         */
        $defs = [
            // ── Line B – Stitching (E2 shift) – OT workers first ──────────────
            ['name'=>'Usman Farooq',    'cnic'=>'35202-9110001-3','grade'=>'A','shift'=>'E2','line'=>'LB','bio'=>'BIO-B001'],
            ['name'=>'Hamid Ali',       'cnic'=>'35202-9110002-5','grade'=>'B','shift'=>'E2','line'=>'LB','bio'=>'BIO-B002'],
            ['name'=>'Kashif Mahmood',  'cnic'=>'35202-9110003-7','grade'=>'A','shift'=>'E2','line'=>'LB','bio'=>'BIO-B003'],
            ['name'=>'Imran Siddiq',    'cnic'=>'35202-9110004-9','grade'=>'B','shift'=>'E2','line'=>'LB','bio'=>'BIO-B004'],
            ['name'=>'Farrukh Baig',    'cnic'=>'35202-9110005-1','grade'=>'C','shift'=>'E2','line'=>'LB','bio'=>'BIO-B005'],

            // ── Line A – Cutting (GA shift) ───────────────────────────────────
            ['name'=>'Muhammad Bilal',  'cnic'=>'35201-9110006-3','grade'=>'A','shift'=>'GA','line'=>'LA','bio'=>'BIO-A001'],
            ['name'=>'Ahmed Raza',      'cnic'=>'35201-9110007-5','grade'=>'B','shift'=>'GA','line'=>'LA','bio'=>'BIO-A002'],
            ['name'=>'Naeem Khan',      'cnic'=>'35201-9110008-7','grade'=>'C','shift'=>'GA','line'=>'LA','bio'=>'BIO-A003'],
            ['name'=>'Sana Gul',        'cnic'=>'35202-9110009-9','grade'=>'B','shift'=>'E1','line'=>'LA','bio'=>'BIO-A004'],
            ['name'=>'Farida Bibi',     'cnic'=>'35202-9110010-1','grade'=>'C','shift'=>'E1','line'=>'LA','bio'=>'BIO-A005'],

            // ── Line C – Lasting/Assembly (E1 + E3 shifts) ───────────────────
            ['name'=>'Zubair Ahmed',    'cnic'=>'35401-9110011-3','grade'=>'A','shift'=>'E1','line'=>'LC','bio'=>'BIO-C001'],
            ['name'=>'Aslam Butt',      'cnic'=>'35401-9110012-5','grade'=>'B','shift'=>'E1','line'=>'LC','bio'=>'BIO-C002'],
            ['name'=>'Rehman Iqbal',    'cnic'=>'35401-9110013-7','grade'=>'B','shift'=>'E3','line'=>'LC','bio'=>'BIO-C003'],
            ['name'=>'Shehnaz Parveen', 'cnic'=>'35202-9110014-9','grade'=>'C','shift'=>'E3','line'=>'LC','bio'=>'BIO-C004'],
            ['name'=>'Azhar Hussain',   'cnic'=>'35401-9110015-1','grade'=>'D','shift'=>'E3','line'=>'LC','bio'=>'BIO-C005'],

            // ── Line D – Finishing/Packing (GA + E1 + E3 shifts) ─────────────
            ['name'=>'Nasreen Akhtar',  'cnic'=>'35202-9110016-3','grade'=>'A','shift'=>'GA','line'=>'LD','bio'=>'BIO-D001'],
            ['name'=>'Shahid Mehmood',  'cnic'=>'35501-9110017-5','grade'=>'B','shift'=>'GA','line'=>'LD','bio'=>'BIO-D002'],
            ['name'=>'Tahir Iqbal',     'cnic'=>'35501-9110018-7','grade'=>'C','shift'=>'E1','line'=>'LD','bio'=>'BIO-D003'],
            ['name'=>'Robina Bibi',     'cnic'=>'35202-9110019-9','grade'=>'C','shift'=>'E1','line'=>'LD','bio'=>'BIO-D004'],
            ['name'=>'Muneer Ahmed',    'cnic'=>'35501-9110020-1','grade'=>'D','shift'=>'E3','line'=>'LD','bio'=>'BIO-D005'],
        ];

        $workers   = [];
        $seq       = 1;
        $joinBase  = now()->subMonths(8);

        foreach ($defs as $def) {
            $lineId = $lines[$def['line']]->id;

            $existing = Worker::where('cnic', $def['cnic'])->first();
            if ($existing) {
                $workers[] = $existing;
                $seq++;
                continue;
            }

            $w = Worker::create([
                'contractor_id'   => $contractor->id,
                'name'            => $def['name'],
                'cnic'            => $def['cnic'],
                'biometric_id'    => $def['bio'],
                'worker_type'     => 'contractual',
                'grade'           => $def['grade'],
                'default_shift'   => $def['shift'],
                'default_line_id' => $lineId,
                'payment_method'  => 'cash',
                'join_date'       => $joinBase->copy()->subDays($seq * 7)->toDateString(),
                'status'          => 'active',
            ]);

            $workers[] = $w;
            $seq++;
        }

        $this->command->info('  ✓ 20 workers seeded');
        return $workers;
    }

    // ── 8. Worker compliance ──────────────────────────────────────────────────

    private function seedCompliance(array $workers): void
    {
        foreach ($workers as $i => $worker) {
            $seq   = str_pad($i + 1, 4, '0', STR_PAD_LEFT);
            $hasIt = DB::table('worker_compliance')->where('worker_id', $worker->id)->exists();
            if ($hasIt) {
                continue;
            }

            DB::table('worker_compliance')->insert([
                'worker_id'              => $worker->id,
                'eobi_number'            => "EOBI-BATA-{$seq}",
                'pessi_number'           => "PESSI-LHR-{$seq}",
                'eobi_registered_at'     => $worker->join_date,
                'pessi_registered_at'    => $worker->join_date,
                'wht_applicable'         => false,
                'bata_dispensary_member' => ($i % 4 === 0),  // every 4th worker enrolled
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
        }

        $this->command->info('  ✓ 20 compliance records (EOBI + PESSI)');
    }

    // ── 9. Production records ─────────────────────────────────────────────────

    /**
     * Per-worker task and daily pair count (standard tier).
     * Usman (idx 0) and Hamid (idx 1) get an extra OT shift_adjustment on Wednesday.
     */
    private function seedProductionRecords(
        array  $workers,
        array  $lines,
        array  $rceMap,
        object $supervisor
    ): void {
        // [task, grade, tier, pairs_per_day] — indexed by worker position
        $workerDefs = [
            // Line B – Stitching
            ['task'=>'Stitching',  'line'=>'LB', 'tier'=>'standard'],   // 0  Usman A
            ['task'=>'Stitching',  'line'=>'LB', 'tier'=>'standard'],   // 1  Hamid B
            ['task'=>'Stitching',  'line'=>'LB', 'tier'=>'standard'],   // 2  Kashif A
            ['task'=>'Stitching',  'line'=>'LB', 'tier'=>'complex'],    // 3  Imran B
            ['task'=>'Stitching',  'line'=>'LB', 'tier'=>'standard'],   // 4  Farrukh C
            // Line A – Cutting
            ['task'=>'Cutting',    'line'=>'LA', 'tier'=>'standard'],   // 5  Bilal A
            ['task'=>'Cutting',    'line'=>'LA', 'tier'=>'standard'],   // 6  Ahmed B
            ['task'=>'Cutting',    'line'=>'LA', 'tier'=>'standard'],   // 7  Naeem C
            ['task'=>'Cutting',    'line'=>'LA', 'tier'=>'complex'],    // 8  Sana B
            ['task'=>'Cutting',    'line'=>'LA', 'tier'=>'standard'],   // 9  Farida C
            // Line C – Lasting
            ['task'=>'Lasting',    'line'=>'LC', 'tier'=>'standard'],   // 10 Zubair A
            ['task'=>'Lasting',    'line'=>'LC', 'tier'=>'standard'],   // 11 Aslam B
            ['task'=>'Lasting',    'line'=>'LC', 'tier'=>'complex'],    // 12 Rehman B
            ['task'=>'Lasting',    'line'=>'LC', 'tier'=>'standard'],   // 13 Shehnaz C
            ['task'=>'Lasting',    'line'=>'LC', 'tier'=>'standard'],   // 14 Azhar D (low output)
            // Line D – Finishing
            ['task'=>'Finishing',  'line'=>'LD', 'tier'=>'standard'],   // 15 Nasreen A
            ['task'=>'Finishing',  'line'=>'LD', 'tier'=>'standard'],   // 16 Shahid B
            ['task'=>'Packing',    'line'=>'LD', 'tier'=>'standard'],   // 17 Tahir C
            ['task'=>'Packing',    'line'=>'LD', 'tier'=>'standard'],   // 18 Robina C
            ['task'=>'Finishing',  'line'=>'LD', 'tier'=>'standard'],   // 19 Muneer D (low output)
        ];

        // Pairs per day by [grade][task][tier]
        $pairsMap = [
            'A' => ['Cutting'=>['standard'=>32,'complex'=>22],'Stitching'=>['standard'=>30,'complex'=>20],
                    'Lasting'=>['standard'=>28,'complex'=>18],'Finishing'=>['standard'=>31,'complex'=>21],'Packing'=>['standard'=>35,'complex'=>24]],
            'B' => ['Cutting'=>['standard'=>26,'complex'=>18],'Stitching'=>['standard'=>25,'complex'=>17],
                    'Lasting'=>['standard'=>23,'complex'=>16],'Finishing'=>['standard'=>25,'complex'=>17],'Packing'=>['standard'=>28,'complex'=>19]],
            'C' => ['Cutting'=>['standard'=>20,'complex'=>14],'Stitching'=>['standard'=>19,'complex'=>13],
                    'Lasting'=>['standard'=>18,'complex'=>12],'Finishing'=>['standard'=>19,'complex'=>13],'Packing'=>['standard'=>21,'complex'=>14]],
            'D' => ['Cutting'=>['standard'=>12,'complex'=>8], 'Stitching'=>['standard'=>11,'complex'=>7],
                    'Lasting'=>['standard'=>10,'complex'=>7], 'Finishing'=>['standard'=>11,'complex'=>7], 'Packing'=>['standard'=>12,'complex'=>8]],
        ];

        $insertCount = 0;

        foreach ($workers as $idx => $worker) {
            $def    = $workerDefs[$idx];
            $lineId = $lines[$def['line']]->id;
            $grade  = $worker->grade;
            $task   = $def['task'];
            $tier   = $def['tier'];
            $shift  = $worker->default_shift;

            $rce        = $rceMap[$task][$grade][$tier] ?? null;
            $rceId      = $rce?->id;
            $rateAmount = $rce ? (float) $rce->rate_pkr : 0.0;
            $pairsDay   = $pairsMap[$grade][$task][$tier] ?? 15;

            foreach (self::WORK_DATES as $date) {
                // Vary pairs slightly by day (±2) to look realistic
                $dayOffset = (int) date('N', strtotime($date)) - 1; // 0=Mon … 5=Sat
                $pairs     = max(1, $pairsDay + ($dayOffset % 3) - 1);

                // Saturday is shorter for E1 workers (half-day config)
                if ($date === '2026-04-11' && in_array($shift, ['E1', 'E2', 'E3'])) {
                    $pairs = (int) ceil($pairs * 0.6);
                }

                $gross = round($pairs * $rateAmount, 2);

                // OT shift_adjustment for Usman (idx 0) and Hamid (idx 1) on Wednesday
                $shiftAdj = 0.0;
                if (in_array($idx, [0, 1]) && $date === '2026-04-08') {
                    // Night-OT premium: 2 extra hours × hourly equivalent
                    $hourlyRate  = $rateAmount > 0
                        ? round((self::MIN_WEEKLY_WAGE / 45) * 1.0, 2)
                        : 0.0;
                    $shiftAdj    = round($hourlyRate * 2, 2);
                }

                $alreadyExists = DB::table('production_records')
                    ->where('worker_id', $worker->id)
                    ->where('work_date', $date)
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                DB::table('production_records')->insert([
                    'worker_id'             => $worker->id,
                    'line_id'               => $lineId,
                    'rate_card_entry_id'    => $rceId,
                    'work_date'             => $date,
                    'shift'                 => $shift,
                    'task'                  => $task,
                    'pairs_produced'        => $pairs,
                    'rate_amount'           => $rateAmount,
                    'gross_earnings'        => $gross,
                    'source_tag'            => 'manual_supervisor',
                    'shift_adjustment'      => $shiftAdj,
                    'shift_adj_authorized_by' => $shiftAdj > 0 ? $supervisor->id : null,
                    'shift_adj_reason'      => $shiftAdj > 0 ? 'Night OT – 2 extra hours Wednesday' : null,
                    'validation_status'     => 'validated',
                    'is_locked'             => false,
                    'ghost_risk_level'      => 'none',
                    'billing_contractor_id' => $worker->contractor_id,
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);

                $insertCount++;
            }
        }

        $this->command->info("  ✓ {$insertCount} production records (2026-W15)");
    }

    // ── 10. Attendance records ────────────────────────────────────────────────

    private function seedAttendanceRecords(array $workers): void
    {
        $insertCount = 0;

        foreach ($workers as $worker) {
            foreach (self::WORK_DATES as $date) {
                $exists = DB::table('attendance_records')
                    ->where('worker_id', $worker->id)
                    ->where('work_date', $date)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Determine punch times from shift config
                $shiftConf  = config('pieceworks.shifts.' . $worker->default_shift, []);
                $startTime  = $shiftConf['start'] ?? '07:00';
                $punchIn    = $date . ' ' . $startTime . ':00';
                $punchOut   = $date . ' ' . ($shiftConf['end'] ?? '17:00') . ':00';

                // Overnight shifts: punch-out is next day
                if (in_array($worker->default_shift, ['E3', 'GB'])) {
                    $punchOut = date('Y-m-d', strtotime($date . ' +1 day'))
                                . ' ' . ($shiftConf['end'] ?? '06:00') . ':00';
                }

                DB::table('attendance_records')->insert([
                    'worker_id'            => $worker->id,
                    'work_date'            => $date,
                    'status'               => 'present',
                    'biometric_punch_in'   => $punchIn,
                    'biometric_punch_out'  => $punchOut,
                    'source'               => 'biometric',
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);

                $insertCount++;
            }
        }

        $this->command->info("  ✓ {$insertCount} attendance records");
    }

    // ── 11. Payroll run ────────────────────────────────────────────────────────

    private function seedPayrollRun(): object
    {
        $existing = DB::table('weekly_payroll_runs')
            ->where('week_ref', self::WEEK_REF)
            ->first();

        if ($existing) {
            $this->command->info('  ✓ Payroll run already exists — reusing');
            return $existing;
        }

        $id = DB::table('weekly_payroll_runs')->insertGetId([
            'week_ref'         => self::WEEK_REF,
            'start_date'       => self::WEEK_START,
            'end_date'         => self::WEEK_END,
            'status'           => 'open',
            'total_gross'      => 0,
            'total_topups'     => 0,
            'total_deductions' => 0,
            'total_net'        => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->command->info('  ✓ Payroll run created: ' . self::WEEK_REF);
        return DB::table('weekly_payroll_runs')->find($id);
    }

    // ── 12. Worker weekly payrolls ─────────────────────────────────────────────

    private function seedWorkerPayrolls(array $workers, object $run, array $rceMap): void
    {
        $totalGross      = 0.0;
        $totalTopups     = 0.0;
        $totalDeductions = 0.0;
        $totalNet        = 0.0;

        foreach ($workers as $worker) {
            $exists = DB::table('worker_weekly_payroll')
                ->where('payroll_run_id', $run->id)
                ->where('worker_id', $worker->id)
                ->exists();

            if ($exists) {
                continue;
            }

            // Aggregate production records for this worker this week
            $records = DB::table('production_records')
                ->where('worker_id', $worker->id)
                ->whereBetween('work_date', [self::WEEK_START, self::WEEK_END])
                ->where('validation_status', '!=', 'rejected')
                ->get();

            $grossEarnings = (float) $records->sum('gross_earnings');
            $otPremium     = (float) $records->filter(fn($r) => $r->shift_adjustment > 0)->sum('shift_adjustment');

            // OT split (simplified: all OT is night OT for E2 workers)
            $isNightOt      = in_array($worker->default_shift, ['E2', 'E3', 'GB']);
            $otRegularHours = 0.0;
            $otNightHours   = 0.0;
            $otRegularAmt   = 0.0;
            $otNightAmt     = 0.0;

            if ($otPremium > 0) {
                $otRegularHours = 2.0;  // OT Wednesday = 2 extra hours
                $otNightHours   = $isNightOt ? 2.0 : 0.0;
                $otRegularAmt   = $isNightOt ? round($otPremium * 0.6, 2) : $otPremium;
                $otNightAmt     = $isNightOt ? round($otPremium - $otRegularAmt, 2) : 0.0;
            }

            $shiftAllowance = 500.00;
            $holidayPay     = 0.0;

            $totalBeforeFloor = $grossEarnings + $otPremium + $shiftAllowance + $holidayPay;
            $minWageTopup     = max(0.0, round(self::MIN_WEEKLY_WAGE - $totalBeforeFloor, 2));
            $totalGrossWorker = round($totalBeforeFloor + $minWageTopup, 2);
            $netPay           = $totalGrossWorker;   // no deductions in demo

            DB::table('worker_weekly_payroll')->insert([
                'payroll_run_id'       => $run->id,
                'worker_id'            => $worker->id,
                'contractor_id'        => $worker->contractor_id,
                'gross_earnings'       => round($grossEarnings, 2),
                'ot_premium'           => round($otPremium, 2),
                'ot_regular_hours'     => $otRegularHours,
                'ot_regular_amount'    => $otRegularAmt,
                'ot_night_hours'       => $otNightHours,
                'ot_night_amount'      => $otNightAmt,
                'ot_extra_hours'       => 0.0,
                'ot_extra_amount'      => 0.0,
                'shift_allowance'      => $shiftAllowance,
                'holiday_pay'          => $holidayPay,
                'min_wage_supplement'  => $minWageTopup,
                'total_gross'          => $totalGrossWorker,
                'advance_deductions'   => 0.0,
                'rejection_deductions' => 0.0,
                'loan_deductions'      => 0.0,
                'other_deductions'     => 0.0,
                'carry_forward_amount' => 0.0,
                'net_pay'              => $netPay,
                'payment_method'       => $worker->payment_method ?? 'cash',
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            $totalGross      += $totalGrossWorker;
            $totalTopups     += $otPremium + $shiftAllowance + $holidayPay + $minWageTopup;
            $totalNet        += $netPay;
        }

        // Update run totals
        DB::table('weekly_payroll_runs')->where('id', $run->id)->update([
            'total_gross'      => round($totalGross, 2),
            'total_topups'     => round($totalTopups, 2),
            'total_deductions' => round($totalDeductions, 2),
            'total_net'        => round($totalNet, 2),
            'updated_at'       => now(),
        ]);

        $this->command->info('  ✓ 20 worker payroll records + run totals updated');
    }

    // ── 13. Payroll exceptions ─────────────────────────────────────────────────

    private function seedPayrollExceptions(array $workers, object $run): void
    {
        // Find the 2 workers for demo exceptions:
        // Exception 1: Azhar Hussain (idx 14, Grade D) — min_wage_shortfall
        // Exception 2: Muneer Ahmed  (idx 19, Grade D) — min_wage_shortfall + compliance_gap
        $exceptWorkers = [$workers[14], $workers[19]];

        foreach ($exceptWorkers as $i => $worker) {
            $wwp = DB::table('worker_weekly_payroll')
                ->where('payroll_run_id', $run->id)
                ->where('worker_id', $worker->id)
                ->first();

            if (! $wwp) {
                continue;
            }

            // Exception 1: min wage shortfall
            if ((float) $wwp->min_wage_supplement > 0) {
                $alreadyExists = DB::table('payroll_exceptions')
                    ->where('payroll_run_id', $run->id)
                    ->where('worker_id', $worker->id)
                    ->where('exception_type', 'min_wage_shortfall')
                    ->exists();

                if (! $alreadyExists) {
                    DB::table('payroll_exceptions')->insert([
                        'payroll_run_id'           => $run->id,
                        'worker_id'                => $worker->id,
                        'worker_weekly_payroll_id' => $wwp->id,
                        'exception_type'           => 'min_wage_shortfall',
                        'description'              => sprintf(
                            'Piece-rate earnings fell below minimum weekly wage (PKR %s). Top-up applied: PKR %s.',
                            number_format(self::MIN_WEEKLY_WAGE, 2),
                            number_format((float) $wwp->min_wage_supplement, 2)
                        ),
                        'amount'      => (float) $wwp->min_wage_supplement,
                        'resolved_at' => null,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }

            // Exception 2 (second worker only): compliance gap placeholder
            if ($i === 1) {
                $alreadyExists = DB::table('payroll_exceptions')
                    ->where('payroll_run_id', $run->id)
                    ->where('worker_id', $worker->id)
                    ->where('exception_type', 'compliance_gap')
                    ->exists();

                if (! $alreadyExists) {
                    DB::table('payroll_exceptions')->insert([
                        'payroll_run_id'           => $run->id,
                        'worker_id'                => $worker->id,
                        'worker_weekly_payroll_id' => $wwp->id,
                        'exception_type'           => 'compliance_gap',
                        'description'              => 'PESSI registration number missing. Please register via /api/compliance/register-pessi.',
                        'amount'      => null,
                        'resolved_at' => null,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }
        }

        $this->command->info('  ✓ 2 demo payroll exceptions');
    }

    // ── 14. Contractor settlement ──────────────────────────────────────────────

    private function seedContractorSettlement(Contractor $contractor, object $run): void
    {
        $existing = DB::table('contractor_settlements')
            ->where('contractor_id', $contractor->id)
            ->where('payroll_run_id', $run->id)
            ->first();

        if ($existing) {
            $this->command->info('  ✓ Contractor settlement already exists — skipped');
            return;
        }

        // Aggregate bata_owes from production records billed to this contractor
        $agg = DB::table('production_records')
            ->whereBetween('work_date', [self::WEEK_START, self::WEEK_END])
            ->where('validation_status', '!=', 'rejected')
            ->where(function ($q) use ($contractor) {
                $q->where('billing_contractor_id', $contractor->id)
                  ->orWhere(function ($sub) use ($contractor) {
                      $sub->whereNull('billing_contractor_id')
                          ->whereIn('worker_id', function ($wi) use ($contractor) {
                              $wi->select('id')
                                 ->from('workers')
                                 ->where('contractor_id', $contractor->id);
                          });
                  });
            })
            ->selectRaw('SUM(pairs_produced) as total_pairs, SUM(gross_earnings) as bata_owes')
            ->first();

        $totalPairs = (int) ($agg->total_pairs ?? 0);
        $bataOwes   = (float) ($agg->bata_owes ?? 0.0);

        $workersPaid = (float) DB::table('worker_weekly_payroll')
            ->where('payroll_run_id', $run->id)
            ->where('contractor_id', $contractor->id)
            ->sum('net_pay');

        $torRatePct         = 15.00;
        $torAmount          = round($bataOwes * ($torRatePct / 100.0), 2);
        $settlementAfterTor = round($bataOwes + $torAmount, 2);
        $margin             = round($bataOwes - $workersPaid, 2);
        $rateAvg            = $totalPairs > 0 ? round($bataOwes / $totalPairs, 4) : null;

        DB::table('contractor_settlements')->insert([
            'contractor_id'        => $contractor->id,
            'payroll_run_id'       => $run->id,
            'week_ref'             => self::WEEK_REF,
            'total_pairs'          => $totalPairs,
            'contracted_rate_avg'  => $rateAvg,
            'bata_owes'            => $bataOwes,
            'tor_rate_pct'         => $torRatePct,
            'tor_amount'           => $torAmount,
            'settlement_after_tor' => $settlementAfterTor,
            'workers_paid'         => $workersPaid,
            'contractor_margin'    => $margin,
            'settlement_status'    => 'pending',
            'settled_at'           => null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->command->info(sprintf(
            '  ✓ Contractor settlement: bata_owes=PKR %s, TOR=PKR %s, total=PKR %s',
            number_format($bataOwes, 2),
            number_format($torAmount, 2),
            number_format($settlementAfterTor, 2)
        ));
    }

    // ── 15. demo_credentials.md ────────────────────────────────────────────────

    private function seedDemoCredentialsFile(array $users): void
    {
        $path = base_path('demo_credentials.md');

        $content = <<<'MD'
# PieceWorks Demo Credentials

> **Environment:** Bata Pakistan – Lahore Factory Demo
> **Week in scope:** 2026-W15 (April 6–11, 2026)
> **Default password for ALL demo accounts:** `Password@1`

---

## Demo User Accounts

| Role               | Name                   | Email                         | Password     |
|--------------------|------------------------|-------------------------------|--------------|
| Payroll Manager    | Adnan Payroll Manager  | payroll.manager@bata.demo     | Password@1   |
| Supervisor         | Kamran Supervisor      | supervisor@bata.demo          | Password@1   |
| Contractor Portal  | Khan Portal            | contractor@bata.demo          | Password@1   |
| QC Inspector       | Sajida QC Inspector    | qc.inspector@bata.demo        | Password@1   |

---

## Demo Data Highlights

- **20 workers** across 4 lines (Cutting · Stitching · Lasting · Finishing/Packing)
- **Contractor:** Khan Labour Services — TOR 15%
- **Payroll week:** 2026-W15 (Apr 6–11, 2026) with pre-calculated run
- **OT demo:** Usman Farooq & Hamid Ali — night OT shift adjustment on Wednesday Apr 8
- **Min-wage exceptions:** Azhar Hussain & Muneer Ahmed (Grade D, low output)
- **Compliance gap:** Muneer Ahmed — PESSI number missing

---

## Reset Demo Data

```bash
php artisan demo:bata-reset
```

This truncates all 2026-W15 records and re-seeds fresh demo data.

---

*Generated by BataDemoSeeder — do not commit to production environments.*
MD;

        file_put_contents($path, $content);
        $this->command->info("  ✓ demo_credentials.md written to project root");
    }
}
