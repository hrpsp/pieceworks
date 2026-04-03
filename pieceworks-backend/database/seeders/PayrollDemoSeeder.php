<?php

namespace Database\Seeders;

use App\Models\PayrollException;
use App\Models\ProductionRecord;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use App\Models\WorkerWeeklyPayroll;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * PayrollDemoSeeder
 *
 * Seeds realistic WorkerWeeklyPayroll lines for week 2026-W14, turning the
 * open stub in DemoDataSeeder into a browsable payroll run with per-worker
 * breakdowns, deductions, and correct total figures.
 *
 * Workers and their scenarios:
 *   Muhammad Asif  (grade A) — high earner, Stitching daily_grade
 *   Sajjad Hussain (grade B) — mid earner
 *   Nadia Bibi     (grade C) — low earner but above min-wage
 *   Tariq Ahmed    (grade B) — complex-tier bonus
 *   Shafiq Ur Rahman (grade A) — senior, small advance deduction
 *   Zainab Fatima  (grade C) — min-wage shortfall → top-up
 *   Khalid Mehmood (grade B) — normal
 *   Farhan Iqbal   (grade C) — ghost-risk flag, one absent day
 *
 * Min-wage floor for Punjab 2026: PKR 37,000 / month → ≈ PKR 8,538 / week (6-day week)
 * Simplified for demo: floor = PKR 7,500 / week.
 */
class PayrollDemoSeeder extends Seeder
{
    private const WEEK_REF   = '2026-W14';
    private const START_DATE = '2026-03-30';
    private const END_DATE   = '2026-04-04';

    // Minimum weekly wage floor (PKR) used for demo
    private const MIN_WAGE_FLOOR = 7500.00;

    public function run(): void
    {
        $this->command->info('Seeding payroll worker lines for ' . self::WEEK_REF . ' …');

        // Fetch or create the payroll run
        $run = WeeklyPayrollRun::firstOrCreate(
            ['week_ref' => self::WEEK_REF],
            [
                'start_date'       => self::START_DATE,
                'end_date'         => self::END_DATE,
                'status'           => 'open',
                'total_gross'      => 0,
                'total_topups'     => 0,
                'total_deductions' => 0,
                'total_net'        => 0,
            ]
        );

        // Gather production totals per worker for this week
        $productionTotals = ProductionRecord::query()
            ->whereBetween('work_date', [self::START_DATE, self::END_DATE])
            ->whereNotIn('validation_status', ['rejected', 'voided'])
            ->select(
                'worker_id',
                DB::raw('SUM(pairs_produced) as total_pairs'),
                DB::raw('SUM(gross_earnings)  as gross_earnings'),
                DB::raw('COUNT(*) as days_worked')
            )
            ->groupBy('worker_id')
            ->get()
            ->keyBy('worker_id');

        $workers    = Worker::whereIn('cnic', [
            '35201-1234567-1', // Asif
            '35201-2345678-2', // Sajjad
            '35202-3456789-0', // Nadia
            '35401-4567890-3', // Tariq
            '35401-5678901-4', // Shafiq
            '35202-6789012-9', // Zainab
            '35501-7890123-5', // Khalid
            '35501-8901234-6', // Farhan
        ])->get()->keyBy('cnic');

        // Per-worker payroll config  [gross_override, ot, shift_allow, advance_ded, reject_ded, loan_ded, other_ded, payment_method]
        $configs = [
            '35201-1234567-1' => [null,   320.00, 150.00,  0.00,    0.00,  0.00,   0.00,  'easypaisa'],  // Asif — high earner, OT bonus
            '35201-2345678-2' => [null,     0.00, 150.00,  0.00,    0.00,  0.00,   0.00,  'easypaisa'],  // Sajjad
            '35202-3456789-0' => [null,     0.00, 150.00,  0.00,    0.00,  0.00,   0.00,  'jazzcash'],   // Nadia
            '35401-4567890-3' => [null,   160.00, 150.00,  500.00,  0.00,  0.00,   0.00,  'bank'],       // Tariq — advance deduction
            '35401-5678901-4' => [null,   480.00, 150.00,  0.00,    0.00, 1200.00, 0.00,  'easypaisa'],  // Shafiq — loan repayment
            '35202-6789012-9' => [null,     0.00, 150.00,  0.00,  120.00,  0.00,   0.00,  'jazzcash'],   // Zainab — rejection deduction
            '35501-7890123-5' => [null,     0.00, 150.00,  0.00,    0.00,  0.00,   0.00,  'bank'],       // Khalid
            '35501-8901234-6' => [null,     0.00, 150.00,  0.00,    0.00,  0.00,   0.00,  'easypaisa'],  // Farhan — absent day 4
        ];

        $totalGross      = 0;
        $totalTopups     = 0;
        $totalDeductions = 0;
        $totalNet        = 0;

        foreach ($configs as $cnic => [$overrideGross, $ot, $shiftAllow, $advDed, $rejDed, $loanDed, $otherDed, $payMethod]) {
            $worker = $workers[$cnic] ?? null;
            if (! $worker) continue;

            $prod          = $productionTotals[$worker->id] ?? null;
            $grossEarnings = $overrideGross ?? (float) ($prod?->gross_earnings ?? 0);

            // Min-wage top-up
            $topup = max(0, self::MIN_WAGE_FLOOR - $grossEarnings);

            // Gross including shift allowance + OT + topup
            $totalGrossLine = $grossEarnings + $ot + $shiftAllow + $topup;

            // Total deductions
            $deductions = $advDed + $rejDed + $loanDed + $otherDed;

            $netPay = max(0, $totalGrossLine - $deductions);

            // Upsert worker payroll line
            WorkerWeeklyPayroll::updateOrCreate(
                [
                    'payroll_run_id' => $run->id,
                    'worker_id'      => $worker->id,
                ],
                [
                    'gross_earnings'       => $grossEarnings,
                    'ot_premium'           => $ot,
                    'shift_allowance'      => $shiftAllow,
                    'min_wage_supplement'  => $topup,
                    'total_gross'          => $totalGrossLine,
                    'advance_deductions'   => $advDed,
                    'rejection_deductions' => $rejDed,
                    'loan_deductions'      => $loanDed,
                    'other_deductions'     => $otherDed,
                    'net_pay'              => $netPay,
                    'payment_method'       => $payMethod,
                    'payment_status'       => 'pending',
                ]
            );

            $totalGross      += $totalGrossLine;
            $totalTopups     += $topup;
            $totalDeductions += $deductions;
            $totalNet        += $netPay;

            $this->command->info(sprintf(
                '    %-22s gross ₨%7.0f  topup ₨%5.0f  ded ₨%5.0f  net ₨%7.0f',
                $worker->name, $grossEarnings, $topup, $deductions, $netPay
            ));
        }

        // Update run totals
        $run->update([
            'total_gross'      => $totalGross,
            'total_topups'     => $totalTopups,
            'total_deductions' => $totalDeductions,
            'total_net'        => $totalNet,
        ]);

        $this->command->info('');
        $this->command->info('  Payroll run totals updated:');
        $this->command->table(
            ['Metric', 'Amount (PKR)'],
            [
                ['Total Gross',      number_format($totalGross, 2)],
                ['Min-wage Top-ups', number_format($totalTopups, 2)],
                ['Total Deductions', number_format($totalDeductions, 2)],
                ['Total Net Pay',    number_format($totalNet, 2)],
            ]
        );

        // Refresh exceptions to match actual data
        $zainab = $workers['35202-6789012-9'] ?? null;
        $farhan  = $workers['35501-8901234-6'] ?? null;

        if ($zainab) {
            $grossZ = (float) ($productionTotals[$zainab->id]?->gross_earnings ?? 0);
            $topupZ = max(0, self::MIN_WAGE_FLOOR - $grossZ);
            if ($topupZ > 0) {
                $wwp = WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
                    ->where('worker_id', $zainab->id)->first();
                PayrollException::firstOrCreate(
                    [
                        'payroll_run_id' => $run->id,
                        'worker_id'      => $zainab->id,
                        'exception_type' => 'min_wage_shortfall',
                    ],
                    [
                        'worker_weekly_payroll_id' => $wwp?->id,
                        'description'              => "Zainab Fatima's gross earnings (PKR " . number_format($grossZ, 0) . ") fall below the minimum wage floor (PKR " . number_format(self::MIN_WAGE_FLOOR, 0) . "/week). A top-up of PKR " . number_format($topupZ, 0) . " has been applied.",
                        'amount'                   => $topupZ,
                        'resolved_at'              => null,
                    ]
                );
            }
        }

        if ($farhan) {
            $wwpF = WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
                ->where('worker_id', $farhan->id)->first();
            PayrollException::firstOrCreate(
                [
                    'payroll_run_id' => $run->id,
                    'worker_id'      => $farhan->id,
                    'exception_type' => 'disputed_records',
                ],
                [
                    'worker_weekly_payroll_id' => $wwpF?->id,
                    'description'              => 'Farhan Iqbal has a medium ghost-risk flag on 2026-04-03 (Thursday). Attendance should be verified before payroll is locked.',
                    'amount'                   => null,
                    'resolved_at'              => null,
                ]
            );
        }

        $this->command->info('  Exceptions refreshed. Run payroll page to review.');
    }
}
