<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\ContractorSettlement;
use App\Models\ContractorPerformanceScore;
use App\Models\PayrollException;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContractorController extends Controller
{
    use ApiResponse;

    // ── GET /api/contractors/dashboard ──────────────────────────────────────────

    /**
     * Admin-side aggregate: total/active contractors, total workers,
     * per-contractor worker breakdown + pending exception counts.
     */
    public function dashboard(): JsonResponse
    {
        $contractors = Contractor::withCount([
            'workers',
            'workers as active_workers_count'   => fn ($q) => $q->where('status', 'active'),
            'workers as inactive_workers_count'  => fn ($q) => $q->where('status', '!=', 'active'),
        ])->get();

        $totalContractors  = $contractors->count();
        $activeContractors = $contractors->where('status', 'active')->count();
        $totalWorkers      = Worker::count();

        // Per-contractor breakdown with pending exceptions (from the latest run)
        $breakdown = $contractors->map(function (Contractor $c) {
            $workerIds = Worker::where('contractor_id', $c->id)->pluck('id');
            $pendingExceptions = PayrollException::whereIn('worker_id', $workerIds)
                ->whereNull('resolved_at')
                ->count();

            return [
                'contractor_id'      => $c->id,
                'contractor_name'    => $c->name,
                'active_workers'     => (int) $c->active_workers_count,
                'inactive_workers'   => (int) $c->inactive_workers_count,
                'total_workers'      => (int) $c->workers_count,
                'pending_exceptions' => $pendingExceptions,
                'tor_rate_pct'       => (float) ($c->tor_rate_pct ?? 0),
            ];
        })->values();

        return $this->success([
            'total_contractors'    => $totalContractors,
            'active_contractors'   => $activeContractors,
            'total_workers'        => $totalWorkers,
            'total_settlement_pkr' => 0, // Live settlement is week-specific; use /contractor/settlement/{weekRef}
            'breakdown'            => $breakdown,
        ]);
    }

    // ── GET /api/contractors/settlement/{weekRef} ─────────────────────────────────

    /**
     * Admin cross-contractor settlement summary for a given ISO week.
     * Returns gross / deductions / net per contractor, plus totals.
     */
    public function settlementSummary(string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->first();

        if (! $run) {
            return $this->success([
                'week_ref'    => $weekRef,
                'total_gross' => 0,
                'total_net'   => 0,
                'lines'       => [],
            ]);
        }

        $settlements = ContractorSettlement::where('payroll_run_id', $run->id)
            ->with('contractor:id,name,tor_rate_pct')
            ->get();

        if ($settlements->isEmpty()) {
            return $this->success([
                'week_ref'    => $weekRef,
                'total_gross' => 0,
                'total_net'   => 0,
                'lines'       => [],
            ]);
        }

        $lines = $settlements->map(fn ($s) => [
            'contractor_id'   => $s->contractor_id,
            'contractor_name' => $s->contractor?->name ?? "Contractor #{$s->contractor_id}",
            'worker_count'    => $s->total_workers   ?? 0,
            'gross_earnings'  => (float) ($s->workers_paid  ?? $s->total_gross ?? 0),
            'deductions'      => (float) ($s->total_deductions ?? 0),
            'net_settlement'  => (float) ($s->bata_owes ?? $s->net_settlement ?? 0),
            'tor_rate_pct'    => (float) ($s->contractor?->tor_rate_pct ?? 0),
        ]);

        return $this->success([
            'week_ref'    => $weekRef,
            'total_gross' => $lines->sum('gross_earnings'),
            'total_net'   => $lines->sum('net_settlement'),
            'lines'       => $lines->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Contractor::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $contractors = $query->withCount('workers')->paginate(20);

        return $this->success([
            'data' => $contractors->items(),
            'meta' => [
                'current_page' => $contractors->currentPage(),
                'last_page'    => $contractors->lastPage(),
                'per_page'     => $contractors->perPage(),
                'total'        => $contractors->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:255',
            'ntn_cnic'            => 'nullable|string|max:20',
            'contact_person'      => 'nullable|string|max:255',
            'phone'               => 'nullable|string|max:20',
            'whatsapp'            => 'nullable|string|max:20',
            'contract_start_date' => 'nullable|date',
            'contract_end_date'   => 'nullable|date|after_or_equal:contract_start_date',
            'payment_cycle'       => ['nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'bank_account'        => 'nullable|string|max:30',
            'bank_name'           => 'nullable|string|max:100',
            'portal_access'       => 'boolean',
            'tor_rate_pct'        => 'nullable|numeric|min:0|max:100',
            'status'              => ['nullable', Rule::in(['active', 'suspended', 'expired'])],
        ]);

        $validated['status'] = $validated['status'] ?? 'active';
        $contractor = Contractor::create($validated);

        return $this->created($contractor, 'Contractor created successfully');
    }

    public function show(int $id): JsonResponse
    {
        $contractor = Contractor::with(['workers:id,name,status,grade,worker_type'])->findOrFail($id);

        return $this->success($contractor);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $validated = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'ntn_cnic'            => 'nullable|string|max:20',
            'contact_person'      => 'nullable|string|max:255',
            'phone'               => 'nullable|string|max:20',
            'whatsapp'            => 'nullable|string|max:20',
            'contract_start_date' => 'nullable|date',
            'contract_end_date'   => 'nullable|date',
            'payment_cycle'       => ['nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'bank_account'        => 'nullable|string|max:30',
            'bank_name'           => 'nullable|string|max:100',
            'portal_access'       => 'boolean',
            'tor_rate_pct'        => 'nullable|numeric|min:0|max:100',
            'status'              => ['nullable', Rule::in(['active', 'suspended', 'expired'])],
        ]);

        $contractor->update($validated);

        return $this->success($contractor, 'Contractor updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);
        $contractor->update(['status' => 'expired']);

        return $this->success(null, 'Contractor deactivated');
    }

    public function workers(int $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $workers = $contractor->workers()
            ->with(['compliance:worker_id,eobi_number,pessi_number'])
            ->paginate(20);

        return $this->success([
            'data' => $workers->items(),
            'meta' => [
                'current_page' => $workers->currentPage(),
                'last_page'    => $workers->lastPage(),
                'per_page'     => $workers->perPage(),
                'total'        => $workers->total(),
            ],
        ]);
    }

    public function settlements(Request $request, int $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $query = ContractorSettlement::where('contractor_id', $id)
            ->orderBy('week_ref', 'desc');

        if ($request->filled('week_ref')) {
            $query->where('week_ref', $request->week_ref);
        }

        $settlements = $query->paginate(10);

        return $this->success([
            'data' => $settlements->items(),
            'meta' => [
                'current_page' => $settlements->currentPage(),
                'last_page'    => $settlements->lastPage(),
                'per_page'     => $settlements->perPage(),
                'total'        => $settlements->total(),
            ],
        ]);
    }

    public function performanceScores(int $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $scores = ContractorPerformanceScore::where('contractor_id', $id)
            ->orderBy('week_ref', 'desc')
            ->take(12)
            ->get();

        return $this->success($scores);
    }
}
