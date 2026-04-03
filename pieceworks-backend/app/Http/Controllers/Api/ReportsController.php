<?php

namespace App\Http\Controllers\Api;

use App\Exports\AnnualPayrollSummaryExport;
use App\Exports\ContractorPerformanceExport;
use App\Exports\DailyProductionExport;
use App\Exports\EobiChallanExport;
use App\Exports\GhostWorkerExport;
use App\Exports\LineProductivityExport;
use App\Exports\MinWageComplianceExport;
use App\Exports\RejectionAnalysisExport;
use App\Exports\ShiftAdjustmentsExport;
use App\Exports\TenureMilestonesExport;
use App\Exports\WageCostPerPairExport;
use App\Exports\WorkerEfficiencyExport;
use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\GhostWorkerFlag;
use App\Models\ProductionRecord;
use App\Models\QcRejection;
use App\Models\ShiftAdjustment;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use App\Models\WorkerWeeklyPayroll;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ReportsController extends Controller
{
    // ── Shared helpers ───────────────────────────────────────────────────────

    /**
     * Parse a week_ref like "2024-W15" into [startDate (Carbon Monday), endDate (Carbon Saturday)].
     */
    private function weekBounds(string $weekRef): array
    {
        [$year, $isoWeek] = explode('-W', $weekRef);
        $start = Carbon::now()->setISODate((int) $year, (int) $isoWeek)->startOfDay();
        $end   = $start->copy()->addDays(5); // Saturday (6-day factory week)

        return [$start, $end];
    }

    private function respondOrCsv(Request $request, Collection $rows, object $export, string $filename): mixed
    {
        if ($request->boolean('csv')) {
            return Excel::download($export, $filename . '.csv', \Maatwebsite\Excel\Excel::CSV);
        }

        return $this->success($rows);
    }

    // ── 1. Daily Production ──────────────────────────────────────────────────

    /**
     * GET /api/reports/daily-production?date=YYYY-MM-DD&line_id=
     */
    public function dailyProduction(Request $request): mixed
    {
        $request->validate([
            'date'    => ['required', 'date'],
            'line_id' => ['nullable', 'integer', 'exists:lines,id'],
        ]);

        $query = ProductionRecord::with([
                'worker:id,name,cnic,contractor_id',
                'worker.contractor:id,name',
                'line:id,name',
                'styleSku:id,style_code,sku_code,description',
            ])
            ->whereDate('work_date', $request->date)
            ->orderBy('line_id')
            ->orderBy('worker_id');

        if ($request->filled('line_id')) {
            $query->where('line_id', $request->line_id);
        }

        $records = $query->get();

        $rows = $records->map(fn ($r) => [
            'worker_id'    => $r->worker_id,
            'worker_name'  => $r->worker?->name,
            'cnic'         => $r->worker?->cnic,
            'line'         => $r->line?->name,
            'contractor'   => $r->worker?->contractor?->name,
            'style_sku'    => $r->styleSku ? "{$r->styleSku->style_code} / {$r->styleSku->sku_code}" : null,
            'tier'         => $r->styleSku?->tier ?? null,
            'pieces'       => $r->pairs_produced,
            'rate'         => (float) $r->rate_amount,
            'earnings'     => (float) $r->gross_earnings,
            'work_date'    => $r->work_date?->toDateString(),
        ]);

        return $this->respondOrCsv(
            $request,
            $rows,
            new DailyProductionExport($rows),
            "daily-production-{$request->date}"
        );
    }

    // ── 2. Worker Efficiency ─────────────────────────────────────────────────

    /**
     * GET /api/reports/worker-efficiency?week_ref=2024-W15&contractor_id=
     */
    public function workerEfficiency(Request $request): mixed
    {
        $request->validate([
            'week_ref'      => ['required', 'string', 'regex:/^\d{4}-W\d{2}$/'],
            'contractor_id' => ['nullable', 'integer', 'exists:contractors,id'],
        ]);

        [$start, $end] = $this->weekBounds($request->week_ref);

        $run = WeeklyPayrollRun::where('week_ref', $request->week_ref)->first();

        // If a calculated run exists, use worker_weekly_payroll for accurate figures.
        if ($run) {
            $query = WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
                ->with(['worker:id,name,grade,cnic,contractor_id', 'worker.contractor:id,name']);

            if ($request->filled('contractor_id')) {
                $query->where('contractor_id', $request->contractor_id);
            }

            $wwps = $query->get();

            // Fetch days-worked + piece counts from production_records
            $productionStats = ProductionRecord::select(
                    'worker_id',
                    DB::raw('COUNT(DISTINCT work_date) as days_worked'),
                    DB::raw('SUM(pairs_produced) as total_pieces')
                )
                ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
                ->groupBy('worker_id')
                ->get()
                ->keyBy('worker_id')
                ->map(fn ($s) => ['days_worked' => (int) $s->days_worked, 'total_pieces' => (int) $s->total_pieces]);

            $rows = $wwps->map(function ($wwp) use ($productionStats) {
                $stats      = $productionStats[$wwp->worker_id] ?? ['days_worked' => 0, 'total_pieces' => 0];
                $daysWorked = $stats['days_worked'];
                $pieces     = $stats['total_pieces'];

                return [
                    'worker_id'            => $wwp->worker_id,
                    'worker_name'          => $wwp->worker?->name,
                    'grade'                => $wwp->worker?->grade,
                    'contractor'           => $wwp->worker?->contractor?->name,
                    'days_worked'          => $daysWorked,
                    'total_pieces'         => $pieces,
                    'gross_earnings'       => (float) $wwp->gross_earnings,
                    'min_wage_supplement'  => (float) $wwp->min_wage_supplement,
                    'net_pay'              => (float) $wwp->net_pay,
                    'pieces_per_day'       => $daysWorked > 0 ? round($pieces / $daysWorked, 2) : 0,
                    'earnings_per_piece'   => $pieces > 0 ? round((float) $wwp->gross_earnings / $pieces, 4) : 0,
                ];
            });
        } else {
            // Fall back to raw production_records if no calculated run
            $query = ProductionRecord::select(
                    'worker_id',
                    DB::raw('COUNT(DISTINCT work_date) as days_worked'),
                    DB::raw('SUM(pairs_produced) as total_pieces'),
                    DB::raw('SUM(gross_earnings) as gross_earnings')
                )
                ->with(['worker:id,name,grade,cnic,contractor_id', 'worker.contractor:id,name'])
                ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
                ->groupBy('worker_id');

            if ($request->filled('contractor_id')) {
                $query->whereHas('worker', fn ($q) => $q->where('contractor_id', $request->contractor_id));
            }

            $rows = $query->get()->map(fn ($r) => [
                'worker_id'            => $r->worker_id,
                'worker_name'          => $r->worker?->name,
                'grade'                => $r->worker?->grade,
                'contractor'           => $r->worker?->contractor?->name,
                'days_worked'          => (int) $r->days_worked,
                'total_pieces'         => (int) $r->total_pieces,
                'gross_earnings'       => (float) $r->gross_earnings,
                'min_wage_supplement'  => null,
                'net_pay'              => null,
                'pieces_per_day'       => $r->days_worked > 0 ? round($r->total_pieces / $r->days_worked, 2) : 0,
                'earnings_per_piece'   => $r->total_pieces > 0 ? round($r->gross_earnings / $r->total_pieces, 4) : 0,
            ]);
        }

        return $this->respondOrCsv(
            $request,
            $rows,
            new WorkerEfficiencyExport($rows),
            "worker-efficiency-{$request->week_ref}"
        );
    }

    // ── 3. Wage Cost Per Pair ────────────────────────────────────────────────

    /**
     * GET /api/reports/wage-cost-per-pair?week_ref=2024-W15&style_sku_id=
     */
    public function wageCostPerPair(Request $request): mixed
    {
        $request->validate([
            'week_ref'      => ['required', 'string', 'regex:/^\d{4}-W\d{2}$/'],
            'style_sku_id'  => ['nullable', 'integer', 'exists:style_skus,id'],
        ]);

        [$start, $end] = $this->weekBounds($request->week_ref);

        $query = ProductionRecord::select(
                'style_sku_id',
                DB::raw('SUM(pairs_produced) as total_pieces'),
                DB::raw('SUM(gross_earnings) as total_wage_cost'),
                DB::raw('COUNT(DISTINCT worker_id) as workers')
            )
            ->with('styleSku:id,style_code,sku_code,tier')
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('style_sku_id')
            ->groupBy('style_sku_id');

        if ($request->filled('style_sku_id')) {
            $query->where('style_sku_id', $request->style_sku_id);
        }

        $rows = $query->get()->map(fn ($r) => [
            'style_sku'            => $r->styleSku ? "{$r->styleSku->style_code} / {$r->styleSku->sku_code}" : "SKU #{$r->style_sku_id}",
            'tier'                 => $r->styleSku?->tier,
            'total_pieces'         => (int) $r->total_pieces,
            'total_wage_cost'      => (float) $r->total_wage_cost,
            'wage_cost_per_pair'   => $r->total_pieces > 0 ? round($r->total_wage_cost / $r->total_pieces, 4) : 0,
            'workers'              => (int) $r->workers,
            'avg_pieces_per_worker'=> $r->workers > 0 ? round($r->total_pieces / $r->workers, 2) : 0,
            'week_ref'             => $request->week_ref,
        ]);

        return $this->respondOrCsv(
            $request,
            $rows,
            new WageCostPerPairExport($rows),
            "wage-cost-per-pair-{$request->week_ref}"
        );
    }

    // ── 4. Line Productivity ─────────────────────────────────────────────────

    /**
     * GET /api/reports/line-productivity?week_ref=2024-W15
     */
    public function lineProductivity(Request $request): mixed
    {
        $request->validate([
            'week_ref' => ['required', 'string', 'regex:/^\d{4}-W\d{2}$/'],
        ]);

        [$start, $end] = $this->weekBounds($request->week_ref);

        $production = ProductionRecord::select(
                'line_id',
                DB::raw('COUNT(DISTINCT worker_id) as active_workers'),
                DB::raw('SUM(pairs_produced) as total_pieces'),
                DB::raw('SUM(gross_earnings) as total_wage_cost')
            )
            ->with('line:id,name,contractor_id', 'line.contractor:id,name')
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('line_id')
            ->groupBy('line_id')
            ->get()
            ->keyBy('line_id');

        $rejections = QcRejection::select('production_records.line_id', DB::raw('SUM(qc_rejections.pairs_rejected) as total_rejections'))
            ->join('production_records', 'production_records.id', '=', 'qc_rejections.production_record_id')
            ->whereBetween('qc_rejections.work_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('production_records.line_id')
            ->groupBy('production_records.line_id')
            ->pluck('total_rejections', 'line_id');

        $targets = DB::table('line_targets')
            ->whereBetween('target_date', [$start->toDateString(), $end->toDateString()])
            ->select('line_id', DB::raw('SUM(target_pairs) as target_pieces'))
            ->groupBy('line_id')
            ->pluck('target_pieces', 'line_id');

        $rows = $production->map(function ($p) use ($rejections, $targets) {
            $lineId           = $p->line_id;
            $pieces           = (int) $p->total_pieces;
            $targetPieces     = (int) ($targets[$lineId] ?? 0);
            $totalRejections  = (int) ($rejections[$lineId] ?? 0);

            return [
                'line'                => $p->line?->name ?? "Line #{$lineId}",
                'contractor'          => $p->line?->contractor?->name,
                'active_workers'      => (int) $p->active_workers,
                'total_pieces'        => $pieces,
                'total_wage_cost'     => (float) $p->total_wage_cost,
                'target_pieces'       => $targetPieces,
                'attainment_pct'      => $targetPieces > 0 ? round($pieces / $targetPieces * 100, 1) : null,
                'pieces_per_worker'   => $p->active_workers > 0 ? round($pieces / $p->active_workers, 2) : 0,
                'rejections'          => $totalRejections,
                'rejection_rate_pct'  => $pieces > 0 ? round($totalRejections / $pieces * 100, 2) : 0,
            ];
        })->values();

        return $this->respondOrCsv(
            $request,
            $rows,
            new LineProductivityExport($rows),
            "line-productivity-{$request->week_ref}"
        );
    }

    // ── 5. Min Wage Compliance ───────────────────────────────────────────────

    /**
     * GET /api/reports/min-wage-compliance?week_ref=2024-W15
     */
    public function minWageCompliance(Request $request): mixed
    {
        $request->validate([
            'week_ref' => ['required', 'string', 'regex:/^\d{4}-W\d{2}$/'],
        ]);

        $run = WeeklyPayrollRun::where('week_ref', $request->week_ref)->firstOrFail();

        [$start, $end] = $this->weekBounds($request->week_ref);

        $wwps = WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
            ->with(['worker:id,name,grade,cnic,contractor_id', 'worker.contractor:id,name'])
            ->get();

        // Days worked per worker
        $daysWorked = ProductionRecord::select('worker_id', DB::raw('COUNT(DISTINCT work_date) as days'))
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('worker_id')
            ->pluck('days', 'worker_id');

        $rows = $wwps->map(function ($wwp) use ($daysWorked) {
            $days         = (int) ($daysWorked[$wwp->worker_id] ?? 0);
            $supplement   = (float) $wwp->min_wage_supplement;
            $gross        = (float) $wwp->gross_earnings;

            // Pro-rated min wage: PKR 37,000/month ÷ 26 working days × days_worked
            $minWageMonthly  = 37000;
            $proRated        = $days > 0 ? round($minWageMonthly / 26 * $days, 2) : 0;

            return [
                'worker_id'             => $wwp->worker_id,
                'worker_name'           => $wwp->worker?->name,
                'grade'                 => $wwp->worker?->grade,
                'contractor'            => $wwp->worker?->contractor?->name,
                'days_worked'           => $days,
                'gross_piece_earnings'  => $gross,
                'min_wage_pro_rated'    => $proRated,
                'supplement_paid'       => $supplement,
                'net_pay'               => (float) $wwp->net_pay,
                'status'                => $supplement > 0 ? 'supplemented' : 'above_min_wage',
            ];
        });

        return $this->respondOrCsv(
            $request,
            $rows,
            new MinWageComplianceExport($rows),
            "min-wage-compliance-{$request->week_ref}"
        );
    }

    // ── 6. Shift Adjustments ─────────────────────────────────────────────────

    /**
     * GET /api/reports/shift-adjustments?week_ref=2024-W15
     */
    public function shiftAdjustments(Request $request): mixed
    {
        $request->validate([
            'week_ref' => ['required', 'string', 'regex:/^\d{4}-W\d{2}$/'],
        ]);

        [$start, $end] = $this->weekBounds($request->week_ref);

        $adjustments = ShiftAdjustment::with([
                'worker:id,name,cnic',
                'line:id,name',
                'authorizer:id,name',
            ])
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('work_date')
            ->orderBy('worker_id')
            ->get();

        $rows = $adjustments->map(fn ($a) => [
            'worker_id'        => $a->worker_id,
            'worker_name'      => $a->worker?->name,
            'line'             => $a->line?->name,
            'work_date'        => $a->work_date?->toDateString(),
            'scheduled_shift'  => $a->scheduled_shift,
            'actual_shift'     => $a->actual_shift,
            'hours_gap'        => $a->hours_gap_from_last_shift,
            'overtime_flagged' => $a->overtime_flagged ? 'Yes' : 'No',
            'reason'           => $a->reason,
            'authorized_by'    => $a->authorizer?->name,
            'confirmed_at'     => $a->confirmed_at?->toDateTimeString(),
        ]);

        return $this->respondOrCsv(
            $request,
            $rows,
            new ShiftAdjustmentsExport($rows),
            "shift-adjustments-{$request->week_ref}"
        );
    }

    // ── 7. Ghost Worker ──────────────────────────────────────────────────────

    /**
     * GET /api/reports/ghost-worker?week_ref=2024-W15
     */
    public function ghostWorker(Request $request): mixed
    {
        $request->validate([
            'week_ref' => ['required', 'string', 'regex:/^\d{4}-W\d{2}$/'],
        ]);

        [$start, $end] = $this->weekBounds($request->week_ref);

        $flags = GhostWorkerFlag::with([
                'worker:id,name,cnic,contractor_id',
                'worker.contractor:id,name',
                'overriddenBy:id,name',
            ])
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('work_date')
            ->get();

        $rows = $flags->map(fn ($f) => [
            'worker_id'      => $f->worker_id,
            'worker_name'    => $f->worker?->name,
            'cnic'           => $f->worker?->cnic,
            'contractor'     => $f->worker?->contractor?->name,
            'flag_date'      => $f->work_date?->toDateString(),
            'flag_type'      => $f->risk_level,
            'absence_days'   => null, // Derived externally if needed
            'last_seen_date' => null,
            'override_by'    => $f->overriddenBy?->name,
            'override_note'  => $f->override_reason,
            'override_at'    => $f->overridden_at?->toDateTimeString(),
        ]);

        return $this->respondOrCsv(
            $request,
            $rows,
            new GhostWorkerExport($rows),
            "ghost-worker-{$request->week_ref}"
        );
    }

    // ── 8. Contractor Performance ────────────────────────────────────────────

    /**
     * GET /api/reports/contractor-performance?month=YYYY-MM
     */
    public function contractorPerformance(Request $request): mixed
    {
        $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $monthStart = Carbon::parse($request->month . '-01')->startOfMonth();
        $monthEnd   = $monthStart->copy()->endOfMonth();

        $contractors = Contractor::where('status', 'active')->get();

        $productionStats = ProductionRecord::select(
                'billing_contractor_id',
                DB::raw('COUNT(DISTINCT worker_id) as workers'),
                DB::raw('SUM(pairs_produced) as total_pieces'),
                DB::raw('SUM(gross_earnings) as total_wage_cost')
            )
            ->whereBetween('work_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereNotNull('billing_contractor_id')
            ->groupBy('billing_contractor_id')
            ->get()
            ->keyBy('billing_contractor_id');

        $rejectionStats = QcRejection::select('production_records.billing_contractor_id', DB::raw('SUM(qc_rejections.pairs_rejected) as rejections'))
            ->join('production_records', 'production_records.id', '=', 'qc_rejections.production_record_id')
            ->whereBetween('qc_rejections.work_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereNotNull('production_records.billing_contractor_id')
            ->groupBy('production_records.billing_contractor_id')
            ->pluck('rejections', 'billing_contractor_id');

        $rows = $contractors->map(function ($c) use ($productionStats, $rejectionStats, $request) {
            $prod         = $productionStats[$c->id] ?? null;
            $pieces       = (int) ($prod?->total_pieces ?? 0);
            $rejections   = (int) ($rejectionStats[$c->id] ?? 0);
            $rejectionRate= $pieces > 0 ? $rejections / $pieces : 0;

            // Composite score: delivery 40%, quality 30%, compliance 30%
            $deliveryScore    = $pieces > 0 ? min(100, round($pieces / max(1, $pieces) * 100, 1)) : 0;
            $qualityScore     = round((1 - $rejectionRate) * 100, 1);
            $complianceScore  = 100; // Placeholder — full compliance module is separate
            $compositeScore   = round($deliveryScore * 0.4 + $qualityScore * 0.3 + $complianceScore * 0.3, 1);

            return [
                'contractor'       => $c->name,
                'workers'          => (int) ($prod?->workers ?? 0),
                'total_pieces'     => $pieces,
                'total_wage_cost'  => (float) ($prod?->total_wage_cost ?? 0),
                'rejections'       => $rejections,
                'rejection_rate_pct' => round($rejectionRate * 100, 2),
                'delivery_score'   => $deliveryScore,
                'quality_score'    => $qualityScore,
                'compliance_score' => $complianceScore,
                'composite_score'  => $compositeScore,
                'month'            => $request->month,
            ];
        })->values();

        return $this->respondOrCsv(
            $request,
            $rows,
            new ContractorPerformanceExport($rows),
            "contractor-performance-{$request->month}"
        );
    }

    // ── 9. EOBI Challan ──────────────────────────────────────────────────────

    /**
     * GET /api/reports/eobi-challan?month=MM&year=YYYY
     * Optional: ?pdf=1 returns an inline PDF download.
     */
    public function eobiChallan(Request $request): mixed
    {
        $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year'  => ['required', 'integer', 'min:2000', 'max:2099'],
        ]);

        $month = (int) $request->month;
        $year  = (int) $request->year;

        // EOBI contributions (PKR fixed amounts per Labour Dept schedule)
        $employerContribution = 1850.00;
        $employeeContribution = 370.00;

        // Workers with an EOBI number and active during the month
        $workers = Worker::with(['compliance:worker_id,eobi_number', 'contractor:id,name'])
            ->whereNotNull('eobi_number')
            ->where('status', 'active')
            ->orderBy('contractor_id')
            ->orderBy('name')
            ->get();

        $rows = $workers->map(fn ($w) => [
            'eobi_number'           => $w->eobi_number ?? $w->compliance?->eobi_number,
            'worker_name'           => $w->name,
            'cnic'                  => $w->cnic,
            'contractor'            => $w->contractor?->name,
            'grade'                 => $w->grade,
            'employer_contribution' => $employerContribution,
            'employee_contribution' => $employeeContribution,
            'total'                 => $employerContribution + $employeeContribution,
            'month'                 => $month,
            'year'                  => $year,
        ]);

        $summary = [
            'total_workers'          => $rows->count(),
            'total_employer_contrib' => round($rows->sum('employer_contribution'), 2),
            'total_employee_contrib' => round($rows->sum('employee_contribution'), 2),
            'grand_total'            => round($rows->sum('total'), 2),
            'month'                  => $month,
            'year'                   => $year,
        ];

        if ($request->boolean('pdf')) {
            return $this->eobiChallanPdf($rows, $summary);
        }

        if ($request->boolean('csv')) {
            return Excel::download(
                new EobiChallanExport($rows),
                "eobi-challan-{$year}-{$month}.csv",
                \Maatwebsite\Excel\Excel::CSV
            );
        }

        return $this->success(['summary' => $summary, 'rows' => $rows]);
    }

    private function eobiChallanPdf(Collection $rows, array $summary): mixed
    {
        $html = view('reports.eobi-challan', compact('rows', 'summary'))->render();

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('A4', 'landscape');
            return $pdf->download("eobi-challan-{$summary['year']}-{$summary['month']}.pdf");
        }

        // Fallback: inline HTML if dompdf not installed
        return response($html, 200)->header('Content-Type', 'text/html');
    }

    // ── 10. Tenure Milestones ────────────────────────────────────────────────

    /**
     * GET /api/reports/tenure-milestones?upcoming_days=30
     */
    public function tenureMilestones(Request $request): mixed
    {
        $request->validate([
            'upcoming_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $upcomingDays = (int) $request->input('upcoming_days', 30);
        $today        = Carbon::today();
        $horizon      = $today->copy()->addDays($upcomingDays);

        $milestones = [90, 365, 1095, 1825];

        $workers = Worker::with(['contractor:id,name'])
            ->whereNotNull('join_date')
            ->where('status', 'active')
            ->get();

        $rows = collect();

        foreach ($workers as $worker) {
            $joinDate   = Carbon::parse($worker->join_date);
            $tenureDays = $joinDate->diffInDays($today);

            foreach ($milestones as $milestoneDays) {
                if ($tenureDays >= $milestoneDays) {
                    continue; // Already reached
                }

                $reachesOn    = $joinDate->copy()->addDays($milestoneDays);
                $daysUntil    = (int) $today->diffInDays($reachesOn, false);

                if ($daysUntil < 0 || $daysUntil > $upcomingDays) {
                    continue;
                }

                $milestoneLabel = match ($milestoneDays) {
                    90   => '3 Months',
                    365  => '1 Year',
                    1095 => '3 Years',
                    1825 => '5 Years',
                    default => "{$milestoneDays} Days",
                };

                $rows->push([
                    'worker_id'        => $worker->id,
                    'worker_name'      => $worker->name,
                    'cnic'             => $worker->cnic,
                    'contractor'       => $worker->contractor?->name,
                    'join_date'        => $worker->join_date?->toDateString(),
                    'tenure_days'      => $tenureDays,
                    'milestone_days'   => $milestoneDays,
                    'milestone_label'  => $milestoneLabel,
                    'reaches_on'       => $reachesOn->toDateString(),
                    'days_until'       => $daysUntil,
                    'alerted'          => false,
                ]);
            }
        }

        $rows = $rows->sortBy('days_until')->values();

        return $this->respondOrCsv(
            $request,
            $rows,
            new TenureMilestonesExport($rows),
            "tenure-milestones-upcoming-{$upcomingDays}d"
        );
    }

    // ── 11. Rejection Analysis ───────────────────────────────────────────────

    /**
     * GET /api/reports/rejection-analysis?month=YYYY-MM
     */
    public function rejectionAnalysis(Request $request): mixed
    {
        $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $monthStart = Carbon::parse($request->month . '-01')->startOfMonth();
        $monthEnd   = $monthStart->copy()->endOfMonth();

        $rejections = QcRejection::with([
                'worker:id,name,contractor_id',
                'worker.contractor:id,name',
                'productionRecord:id,line_id,style_sku_id',
                'productionRecord.line:id,name',
                'productionRecord.styleSku:id,style_code,sku_code',
            ])
            ->whereBetween('work_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderBy('work_date')
            ->get();

        $rows = $rejections->map(fn ($r) => [
            'worker_id'      => $r->worker_id,
            'worker_name'    => $r->worker?->name,
            'contractor'     => $r->worker?->contractor?->name,
            'line'           => $r->productionRecord?->line?->name,
            'style_sku'      => $r->productionRecord?->styleSku
                ? "{$r->productionRecord->styleSku->style_code} / {$r->productionRecord->styleSku->sku_code}"
                : null,
            'rejection_date' => $r->work_date?->toDateString(),
            'pieces_rejected'=> $r->pairs_rejected,
            'deduction'      => (float) $r->penalty_amount,
            'reason'         => $r->defect_type,
            'status'         => $r->status,
            'disputed_at'    => $r->disputed_at?->toDateTimeString(),
        ]);

        return $this->respondOrCsv(
            $request,
            $rows,
            new RejectionAnalysisExport($rows),
            "rejection-analysis-{$request->month}"
        );
    }

    // ── 12. Annual Payroll Summary ───────────────────────────────────────────

    /**
     * GET /api/reports/annual-payroll-summary?year=2024
     */
    public function annualPayrollSummary(Request $request): mixed
    {
        $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2099'],
        ]);

        $year  = (int) $request->year;
        $start = Carbon::createFromDate($year, 1, 1)->startOfYear();
        $end   = Carbon::createFromDate($year, 12, 31)->endOfYear();

        $runs = WeeklyPayrollRun::whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('start_date')
            ->get();

        $weeklyAggregates = WorkerWeeklyPayroll::select(
                'payroll_run_id',
                DB::raw('COUNT(*) as workers_paid'),
                DB::raw('SUM(gross_earnings) as total_gross'),
                DB::raw('SUM(min_wage_supplement + ot_premium + shift_allowance + holiday_pay) as total_supplements'),
                DB::raw('SUM(advance_deductions + rejection_deductions + loan_deductions + other_deductions) as total_deductions'),
                DB::raw('SUM(net_pay) as total_net'),
                DB::raw('SUM(advance_deductions) as advance_deductions'),
                DB::raw('SUM(loan_deductions) as loan_deductions'),
                DB::raw('SUM(rejection_deductions) as rejection_deductions')
            )
            ->whereIn('payroll_run_id', $runs->pluck('id'))
            ->groupBy('payroll_run_id')
            ->get()
            ->keyBy('payroll_run_id');

        $piecesByRun = ProductionRecord::select('work_date', DB::raw('SUM(pairs_produced) as total_pieces'))
            ->whereYear('work_date', $year)
            ->groupBy('work_date')
            ->get();

        // Map pieces to run by date range
        $runPieces = [];
        foreach ($runs as $run) {
            $runPieces[$run->id] = $piecesByRun
                ->filter(fn ($p) => Carbon::parse($p->work_date)->between($run->start_date, $run->end_date))
                ->sum('total_pieces');
        }

        $rows = $runs->map(function ($run) use ($weeklyAggregates, $runPieces) {
            $agg = $weeklyAggregates[$run->id] ?? null;

            return [
                'week_ref'              => $run->week_ref,
                'week_start'            => $run->start_date?->toDateString(),
                'week_end'              => $run->end_date?->toDateString(),
                'workers_paid'          => (int) ($agg?->workers_paid ?? 0),
                'total_pieces'          => (int) ($runPieces[$run->id] ?? 0),
                'total_gross'           => (float) ($agg?->total_gross ?? 0),
                'total_supplements'     => (float) ($agg?->total_supplements ?? 0),
                'total_deductions'      => (float) ($agg?->total_deductions ?? 0),
                'total_net'             => (float) ($agg?->total_net ?? 0),
                'advance_deductions'    => (float) ($agg?->advance_deductions ?? 0),
                'loan_deductions'       => (float) ($agg?->loan_deductions ?? 0),
                'rejection_deductions'  => (float) ($agg?->rejection_deductions ?? 0),
                'status'                => $run->status,
            ];
        });

        $totals = [
            'weeks'              => $rows->count(),
            'workers_paid_total' => $rows->sum('workers_paid'),
            'total_pieces'       => $rows->sum('total_pieces'),
            'total_gross'        => round($rows->sum('total_gross'), 2),
            'total_supplements'  => round($rows->sum('total_supplements'), 2),
            'total_deductions'   => round($rows->sum('total_deductions'), 2),
            'total_net'          => round($rows->sum('total_net'), 2),
        ];

        if ($request->boolean('csv')) {
            return Excel::download(
                new AnnualPayrollSummaryExport($rows),
                "annual-payroll-summary-{$year}.csv",
                \Maatwebsite\Excel\Excel::CSV
            );
        }

        return $this->success(['year' => $year, 'totals' => $totals, 'weeks' => $rows]);
    }
}
