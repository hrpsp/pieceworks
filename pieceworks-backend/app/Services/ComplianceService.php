<?php

namespace App\Services;

use App\Models\LeaveApplication;
use App\Models\PublicHoliday;
use App\Models\TenureMilestone;
use App\Models\WorkerWeeklyPayroll;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ComplianceService
{
    // Province minimum monthly wages 2026 (PKR)
    private const MONTHLY_WAGES = [
        'punjab'      => 37_000.00,
        'sindh'       => 37_000.00,
        'kpk'         => 36_000.00,
        'balochistan' => 32_000.00,
        'federal'     => 37_000.00,
    ];

    private const WEEKS_PER_MONTH   = 4.33;
    private const FEDERAL_MIN_WAGE  = 37_000.00; // EOBI base (federal minimum)
    private const EOBI_EMPLOYER_PCT = 0.05;      // 5%
    private const EOBI_EMPLOYEE_PCT = 0.01;      // 1%

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Check whether gross_earnings meet the province minimum wage for the week.
     *
     * Returns:
     * [
     *   topup_amount => float,   // 0.0 if no shortfall
     *   weekly_min   => float,
     *   monthly_min  => float,
     *   province     => string,
     *   shortfall    => bool,
     * ]
     */
    public function checkMinimumWage(int $workerId, float $grossEarnings, string $province): array
    {
        $monthlyMin = self::MONTHLY_WAGES[strtolower($province)] ?? self::MONTHLY_WAGES['punjab'];
        $weeklyMin  = round($monthlyMin / self::WEEKS_PER_MONTH, 2);
        $topup      = max(0.0, round($weeklyMin - $grossEarnings, 2));

        return [
            'topup_amount' => $topup,
            'weekly_min'   => $weeklyMin,
            'monthly_min'  => $monthlyMin,
            'province'     => strtolower($province),
            'shortfall'    => $topup > 0,
        ];
    }

    /**
     * Calculate holiday pay for a work_date that falls on a public holiday.
     *
     * Checks public_holidays for 'all' and 'federal' provinces (and the optional
     * $province argument). Returns avg daily earnings over the last 4 completed
     * payroll weeks, or 0.0 if the date is not a holiday.
     */
    public function calculateHolidayPay(int $workerId, string|Carbon $workDate, ?string $province = null): float
    {
        $date = $workDate instanceof Carbon ? $workDate : Carbon::parse($workDate);

        if (! PublicHoliday::isHoliday($date->toDateString(), $province)) {
            return 0.0;
        }

        return $this->avgDailyEarnings($workerId, $date);
    }

    /**
     * Calculate leave pay for an approved leave application.
     *
     * Formula: avg_daily_earnings × leave_days
     * Also persists avg_daily_earnings_basis and leave_pay_amount on the record.
     */
    public function calculateLeavePay(int $leaveApplicationId): float
    {
        $leave    = LeaveApplication::findOrFail($leaveApplicationId);
        $avgDaily = $this->avgDailyEarnings(
            $leave->worker_id,
            Carbon::parse($leave->from_date)
        );
        $total = round($avgDaily * (int) $leave->days, 2);

        $leave->updateQuietly([
            'avg_daily_earnings_basis' => $avgDaily,
            'leave_pay_amount'         => $total,
        ]);

        return $total;
    }

    /**
     * Project annual earnings from a 4-week rolling average.
     *
     * Returns:
     * [
     *   projected_annual       => float,
     *   taxable_threshold      => float,
     *   wht_applicable         => bool,
     *   weekly_average         => float,
     *   surplus_over_threshold => float,
     * ]
     */
    public function projectAnnualEarnings(int $workerId): array
    {
        $weeklyAvg = (float) (WorkerWeeklyPayroll::where('worker_id', $workerId)
            ->orderByDesc('id')
            ->limit(4)
            ->avg('total_gross') ?? 0.0);

        $projectedAnnual = round($weeklyAvg * 52, 2);
        $whtThreshold    = (float) config('pieceworks.wht_threshold', 600_000.00);

        return [
            'projected_annual'       => $projectedAnnual,
            'taxable_threshold'      => $whtThreshold,
            'wht_applicable'         => $projectedAnnual > $whtThreshold,
            'weekly_average'         => round($weeklyAvg, 2),
            'surplus_over_threshold' => max(0.0, round($projectedAnnual - $whtThreshold, 2)),
        ];
    }

    /**
     * EOBI monthly contributions (fixed on federal minimum wage).
     *
     * Employer: 5% × PKR 37,000 = PKR 1,850/month
     * Employee: 1% × PKR 37,000 = PKR   370/month
     */
    public function calculateEOBI(int $workerId): array
    {
        $employerMonthly = round(self::FEDERAL_MIN_WAGE * self::EOBI_EMPLOYER_PCT, 2); // 1,850.00
        $employeeMonthly = round(self::FEDERAL_MIN_WAGE * self::EOBI_EMPLOYEE_PCT, 2); //   370.00

        return [
            'worker_id'        => $workerId,
            'employer_monthly' => $employerMonthly,
            'employee_monthly' => $employeeMonthly,
            'total_monthly'    => round($employerMonthly + $employeeMonthly, 2),
            'employer_weekly'  => round($employerMonthly / self::WEEKS_PER_MONTH, 2),
            'employee_weekly'  => round($employeeMonthly / self::WEEKS_PER_MONTH, 2),
        ];
    }

    /**
     * Record and return newly triggered (unalerted) tenure milestones for a worker.
     *
     * Milestones: 90 days, 1 year (365), 3 years (1095), 5 years (1825).
     * Uses firstOrCreate so re-runs are idempotent.
     *
     * @return array  Array of milestone data including 'label' for each unalerted milestone.
     */
    public function checkTenureMilestones(int $workerId, ?Carbon $joinDate): array
    {
        if (! $joinDate) {
            return [];
        }

        $today      = Carbon::today();
        $tenureDays = (int) $joinDate->diffInDays($today);

        foreach ([90, 365, 1095, 1825] as $days) {
            if ($tenureDays >= $days) {
                TenureMilestone::firstOrCreate(
                    ['worker_id' => $workerId, 'milestone_days' => (string) $days],
                    [
                        'reached_at' => $joinDate->copy()->addDays($days)->toDateString(),
                        'alerted'    => false,
                    ]
                );
            }
        }

        return TenureMilestone::where('worker_id', $workerId)
            ->where('alerted', false)
            ->get()
            ->map(fn ($m) => array_merge(
                $m->toArray(),
                ['label' => $this->milestoneLabel((int) $m->milestone_days)]
            ))
            ->toArray();
    }

    /**
     * Mark all unalerted milestones for a worker as alerted.
     * Called after PayrollExceptions have been created for them.
     */
    public function markMilestonesAlerted(int $workerId): void
    {
        TenureMilestone::where('worker_id', $workerId)
            ->where('alerted', false)
            ->update(['alerted' => true]);
    }

    /**
     * Human-readable label for a milestone day count.
     */
    public function milestoneLabel(int $days): string
    {
        return match ($days) {
            90    => '90-Day Probation End',
            365   => '1-Year Service Anniversary',
            1095  => '3-Year Service Anniversary',
            1825  => '5-Year Service Anniversary',
            default => "{$days}-Day Milestone",
        };
    }

    // ── Private: shared earnings helper ────────────────────────────────────

    /**
     * Average daily earnings over the 4 completed payroll weeks before $referenceDate.
     *
     * Formula:
     *   sum(worker_weekly_payroll.total_gross for last 4 payroll runs ending before ref)
     *   ÷ count(distinct production_record.work_date in same period, status != rejected)
     */
    private function avgDailyEarnings(int $workerId, Carbon $referenceDate): float
    {
        $from = $referenceDate->copy()->subWeeks(4)->toDateString();
        $to   = $referenceDate->copy()->subDay()->toDateString();

        $sumGross = (float) (WorkerWeeklyPayroll::where('worker_id', $workerId)
            ->whereHas('payrollRun', fn ($q) => $q->whereBetween('end_date', [$from, $to]))
            ->sum('total_gross') ?? 0.0);

        $daysWorked = DB::table('production_records')
            ->where('worker_id', $workerId)
            ->whereBetween('work_date', [$from, $to])
            ->whereNotIn('validation_status', ['rejected'])
            ->distinct()
            ->count('work_date');

        if ($daysWorked === 0 || $sumGross === 0.0) {
            return 0.0;
        }

        return round($sumGross / $daysWorked, 2);
    }
}
