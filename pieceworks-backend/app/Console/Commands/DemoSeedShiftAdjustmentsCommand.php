<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Adds shift adjustment demo records to existing W15 data.
 * Safe to run multiple times — checks for duplicates before inserting.
 *
 * Usage:
 *   php artisan demo:seed-shift-adjustments
 */
class DemoSeedShiftAdjustmentsCommand extends Command
{
    protected $signature   = 'demo:seed-shift-adjustments';
    protected $description = 'Seed 5 shift adjustment records for 2026-W15 demo data';

    public function handle(): int
    {
        $this->info('Seeding shift adjustments for 2026-W15 …');

        // Resolve worker IDs by CNIC (known from BataDemoSeeder)
        $workerCnics = [
            'E2→GA' => ['35202-9110001-3', '2026-04-07', 'E2', 'GA', 'Line B – Stitching',        9.0,  false, 'line_shortage',    'Day-shift team short by one stitcher — Usman covered GA slot.',                                    '2026-04-07 07:15:00'],
            'E2→E3' => ['35202-9110002-5', '2026-04-08', 'E2', 'E3', 'Line B – Stitching',        2.0,  true,  'skill_requirement','Overnight rush order — Grade B stitcher required for intricate upper assembly.',                    '2026-04-08 22:10:00'],
            'GA→E1' => ['35201-9110008-7', '2026-04-09', 'GA', 'E1', 'Line A – Cutting',          null, false, 'worker_request',   'Worker requested E1 shift swap to attend family function; approved by supervisor.',                  '2026-04-09 06:05:00'],
            'E3→E2' => ['35401-9110013-7', '2026-04-10', 'E3', 'E2', 'Line C – Lasting/Assembly', 8.0,  false, 'line_shortage',    'E2 shift lasting team was two workers short — Rehman moved up one shift.',                         '2026-04-10 14:20:00'],
            'E1→GA' => ['35501-9110018-7', '2026-04-11', 'E1', 'GA', 'Line D – Finishing/Packing',1.0,  false, 'skill_requirement','Saturday day-shift packing crew needed; Tahir redeployed from E1 to GA.',                         '2026-04-11 07:30:00'],
        ];

        $supervisor = DB::table('users')->where('email', 'supervisor@bata.demo')->first();
        if (! $supervisor) {
            $this->error('Demo supervisor user not found. Run BataDemoSeeder first.');
            return Command::FAILURE;
        }

        $inserted = 0;
        foreach ($workerCnics as [$cnic, $date, $sched, $actual, $lineName, $hoursGap, $otFlag, $reason, $reasonText, $confirmedAt]) {
            $worker = DB::table('workers')->where('cnic', $cnic)->first();
            if (! $worker) {
                $this->warn("  Worker CNIC {$cnic} not found — skipped");
                continue;
            }

            $line = DB::table('lines')->where('name', $lineName)->first();
            if (! $line) {
                $this->warn("  Line '{$lineName}' not found — skipped");
                continue;
            }

            $already = DB::table('shift_adjustments')
                ->where('worker_id', $worker->id)
                ->where('work_date', $date)
                ->exists();

            if ($already) {
                $this->line("  → [{$worker->name} / {$date}] already exists — skipped");
                continue;
            }

            $prodRecord = DB::table('production_records')
                ->where('worker_id', $worker->id)
                ->where('work_date', $date)
                ->first();

            DB::table('shift_adjustments')->insert([
                'production_record_id'     => $prodRecord?->id,
                'worker_id'                => $worker->id,
                'work_date'                => $date,
                'scheduled_shift'          => $sched,
                'actual_shift'             => $actual,
                'line_id'                  => $line->id,
                'hours_gap_from_last_shift'=> $hoursGap,
                'overtime_flagged'         => $otFlag,
                'authorized_by'            => $supervisor->id,
                'reason'                   => $reason,
                'reason_text'              => $reasonText,
                'confirmed_at'             => $confirmedAt,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);

            $this->line("  ✓ {$worker->name}: {$sched} → {$actual} on {$date} ({$reason})");
            $inserted++;
        }

        $this->info("Done — {$inserted} shift adjustment(s) inserted.");
        return Command::SUCCESS;
    }
}
