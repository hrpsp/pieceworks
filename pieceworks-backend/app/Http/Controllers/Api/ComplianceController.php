<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenureMilestone;
use App\Models\Worker;
use App\Models\WorkerCompliance;
use App\Services\ComplianceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceController extends Controller
{
    public function __construct(private ComplianceService $complianceService) {}

    // ── EOBI Report ─────────────────────────────────────────────────────────

    /**
     * GET /api/compliance/eobi-report?month=&year=&download=
     *
     * EOBI contribution challan for a given month, grouped by contractor.
     * Add ?download=1 to receive a CSV file suitable for EOBI portal submission.
     */
    public function eobiReport(Request $request): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'month'    => ['required', 'integer', 'min:1', 'max:12'],
            'year'     => ['required', 'integer', 'min:2020', 'max:2100'],
            'download' => ['nullable', 'boolean'],
        ]);

        $workers = Worker::with(['contractor:id,name', 'compliance'])
            ->where('status', 'active')
            ->orderBy('contractor_id')
            ->orderBy('name')
            ->get(['id', 'name', 'cnic', 'eobi_number', 'contractor_id']);

        $rows = $workers->map(function (Worker $worker) {
            $eobi = $this->complianceService->calculateEOBI($worker->id);
            return [
                'worker_id'        => $worker->id,
                'name'             => $worker->name,
                'cnic'             => $worker->cnic,
                'eobi_number'      => $worker->compliance?->eobi_number ?? $worker->eobi_number,
                'contractor_id'    => $worker->contractor_id,
                'contractor'       => $worker->contractor?->name ?? 'Direct',
                'employer_monthly' => $eobi['employer_monthly'],
                'employee_monthly' => $eobi['employee_monthly'],
                'total_monthly'    => $eobi['total_monthly'],
            ];
        });

        if ($request->boolean('download')) {
            return $this->eobiCsvResponse(
                $rows->toArray(),
                "eobi_challan_{$data['year']}_{$data['month']}.csv"
            );
        }

        $grouped = $rows->groupBy('contractor')->map(fn ($group) => [
            'workers'        => $group->values(),
            'employer_total' => round($group->sum('employer_monthly'), 2),
            'employee_total' => round($group->sum('employee_monthly'), 2),
            'grand_total'    => round($group->sum('total_monthly'), 2),
        ]);

        return $this->success([
            'month'         => (int) $data['month'],
            'year'          => (int) $data['year'],
            'by_contractor' => $grouped,
            'summary'       => [
                'total_workers'        => $workers->count(),
                'employer_grand_total' => round($rows->sum('employer_monthly'), 2),
                'employee_grand_total' => round($rows->sum('employee_monthly'), 2),
                'grand_total'          => round($rows->sum('total_monthly'), 2),
            ],
        ], 'EOBI challan report generated.');
    }

    // ── WHT Report ──────────────────────────────────────────────────────────

    /**
     * GET /api/compliance/wht-report?year=
     *
     * Workers at ≥75% of the annual WHT threshold, ordered by projected earnings.
     * Flags workers who are already over the threshold and require tax registration.
     */
    public function whtReport(Request $request): JsonResponse
    {
        $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        $whtThreshold = (float) config('pieceworks.wht_threshold', 600_000.00);

        $workers = Worker::where('status', 'active')
            ->whereHas('weeklyPayrolls')
            ->with(['contractor:id,name', 'compliance'])
            ->get(['id', 'name', 'cnic', 'contractor_id']);

        $results = $workers
            ->map(function (Worker $worker) use ($whtThreshold) {
                $projection = $this->complianceService->projectAnnualEarnings($worker->id);
                $pct = $projection['projected_annual'] > 0
                    ? round(($projection['projected_annual'] / $whtThreshold) * 100, 1)
                    : 0.0;

                return [
                    'worker_id'        => $worker->id,
                    'name'             => $worker->name,
                    'cnic'             => $worker->cnic,
                    'contractor'       => $worker->contractor?->name ?? 'Direct',
                    'weekly_average'   => $projection['weekly_average'],
                    'projected_annual' => $projection['projected_annual'],
                    'threshold'        => $whtThreshold,
                    'threshold_pct'    => $pct,
                    'wht_applicable'   => $projection['wht_applicable'],
                    'surplus'          => $projection['surplus_over_threshold'],
                    'wht_registered'   => $worker->compliance?->wht_applicable ?? false,
                ];
            })
            ->filter(fn ($r) => $r['threshold_pct'] >= 75.0)
            ->sortByDesc('projected_annual')
            ->values();

        return $this->success([
            'threshold'      => $whtThreshold,
            'workers'        => $results,
            'wht_applicable' => $results->where('wht_applicable', true)->count(),
            'approaching'    => $results->where('wht_applicable', false)->count(),
        ], 'WHT report generated.');
    }

    // ── Tenure Milestones ───────────────────────────────────────────────────

    /**
     * GET /api/compliance/tenure-milestones
     *
     * Workers approaching a milestone (within config lookahead window)
     * and workers who recently reached a milestone but haven't been actioned.
     */
    public function tenureMilestones(): JsonResponse
    {
        $lookAhead  = (int) config('pieceworks.tenure_lookahead_days', 30);
        $milestones = [90, 365, 1095, 1825];
        $today      = Carbon::today();
        $results    = [];

        foreach ($milestones as $days) {
            // Workers whose join_date + $days falls within [today, today+lookAhead]
            $milestoneFrom = $today->copy()->toDateString();
            $milestoneTo   = $today->copy()->addDays($lookAhead)->toDateString();
            $joinFrom      = Carbon::parse($milestoneFrom)->subDays($days)->toDateString();
            $joinTo        = Carbon::parse($milestoneTo)->subDays($days)->toDateString();

            $approaching = Worker::where('status', 'active')
                ->whereNotNull('join_date')
                ->whereBetween('join_date', [$joinFrom, $joinTo])
                ->whereDoesntHave('tenureMilestones', fn ($q) => $q->where('milestone_days', (string) $days))
                ->with('contractor:id,name')
                ->get(['id', 'name', 'join_date', 'contractor_id'])
                ->map(fn ($w) => [
                    'worker_id'      => $w->id,
                    'name'           => $w->name,
                    'join_date'      => $w->join_date?->toDateString(),
                    'contractor'     => $w->contractor?->name ?? 'Direct',
                    'milestone_date' => Carbon::parse($w->join_date)->addDays($days)->toDateString(),
                    'days_remaining' => (int) $today->diffInDays(
                        Carbon::parse($w->join_date)->addDays($days),
                        false
                    ),
                ]);

            // Reached but not yet actioned
            $unalerted = TenureMilestone::where('milestone_days', (string) $days)
                ->where('alerted', false)
                ->with(['worker:id,name,join_date,contractor_id', 'worker.contractor:id,name'])
                ->get()
                ->map(fn ($m) => [
                    'worker_id'  => $m->worker_id,
                    'name'       => $m->worker?->name,
                    'join_date'  => $m->worker?->join_date?->toDateString(),
                    'contractor' => $m->worker?->contractor?->name ?? 'Direct',
                    'reached_at' => $m->reached_at?->toDateString(),
                ]);

            if ($approaching->isNotEmpty() || $unalerted->isNotEmpty()) {
                $results[] = [
                    'milestone_days'   => $days,
                    'label'            => $this->complianceService->milestoneLabel($days),
                    'approaching'      => $approaching->values(),
                    'recently_reached' => $unalerted->values(),
                ];
            }
        }

        return $this->success($results, 'Tenure milestones retrieved.');
    }

    // ── Missing Registrations ───────────────────────────────────────────────

    /**
     * GET /api/compliance/missing-registrations
     *
     * Active workers who have not been registered for EOBI or PESSI.
     */
    public function missingRegistrations(): JsonResponse
    {
        $workers = Worker::where('status', 'active')
            ->where(fn ($q) => $q->whereNull('eobi_number')->orWhereNull('pessi_number'))
            ->with('contractor:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'cnic', 'eobi_number', 'pessi_number', 'join_date', 'contractor_id'])
            ->map(fn ($w) => [
                'worker_id'     => $w->id,
                'name'          => $w->name,
                'cnic'          => $w->cnic,
                'contractor'    => $w->contractor?->name ?? 'Direct',
                'join_date'     => $w->join_date?->toDateString(),
                'missing_eobi'  => is_null($w->eobi_number),
                'missing_pessi' => is_null($w->pessi_number),
            ]);

        return $this->success([
            'workers'       => $workers,
            'total'         => $workers->count(),
            'missing_eobi'  => $workers->where('missing_eobi', true)->count(),
            'missing_pessi' => $workers->where('missing_pessi', true)->count(),
        ], 'Missing registration report generated.');
    }

    // ── EOBI Registration ───────────────────────────────────────────────────

    /**
     * POST /api/compliance/register-eobi
     * body: { worker_id, eobi_number }
     *
     * Upserts the worker_compliance record and syncs workers.eobi_number.
     */
    public function registerEobi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id'   => ['required', 'integer', 'exists:workers,id'],
            'eobi_number' => ['required', 'string', 'max:20'],
        ]);

        $compliance = WorkerCompliance::updateOrCreate(
            ['worker_id' => $data['worker_id']],
            [
                'eobi_number'        => $data['eobi_number'],
                'eobi_registered_at' => now()->toDateString(),
            ]
        );

        Worker::where('id', $data['worker_id'])->update(['eobi_number' => $data['eobi_number']]);

        return $this->created(
            $compliance->load('worker:id,name,cnic'),
            'EOBI number registered successfully.'
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function eobiCsvResponse(array $rows, string $filename): StreamedResponse
    {
        return response()->stream(
            function () use ($rows) {
                $handle = fopen('php://output', 'w');

                // UTF-8 BOM for Excel compatibility
                fwrite($handle, "\xEF\xBB\xBF");

                fputcsv($handle, [
                    'Sr#',
                    'Contractor',
                    'Worker Name',
                    'CNIC',
                    'EOBI Number',
                    'Employer Contribution (PKR)',
                    'Employee Contribution (PKR)',
                    'Total (PKR)',
                ]);

                foreach ($rows as $i => $row) {
                    fputcsv($handle, [
                        $i + 1,
                        $row['contractor'],
                        $row['name'],
                        $row['cnic']          ?? 'N/A',
                        $row['eobi_number']   ?? 'NOT REGISTERED',
                        number_format($row['employer_monthly'], 2),
                        number_format($row['employee_monthly'], 2),
                        number_format($row['total_monthly'], 2),
                    ]);
                }

                fclose($handle);
            },
            200,
            [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control'       => 'no-store',
            ]
        );
    }
}
