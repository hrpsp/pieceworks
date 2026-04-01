<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContractorSettlement;
use App\Models\ProductionRecord;
use App\Models\QcRejection;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use App\Models\WorkerCompliance;
use App\Models\WorkerWeeklyPayroll;
use App\Services\ContractorSettlementService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractorPortalController extends Controller
{
    public function __construct(private ContractorSettlementService $settlementService) {}

    // ── GET /api/contractor/dashboard ───────────────────────────────────────

    /**
     * Current-week snapshot: worker count, pairs produced, bata_owes, workers_paid, margin.
     * If a settlement has not been calculated yet, figures are derived live from raw data.
     */
    public function dashboard(): JsonResponse
    {
        $contractorId = $this->contractorId();
        $weekRef      = Carbon::now()->format('o-\WW');

        $run        = WeeklyPayrollRun::where('week_ref', $weekRef)->first();
        $settlement = $run
            ? ContractorSettlement::where('contractor_id', $contractorId)
                ->where('payroll_run_id', $run->id)
                ->first()
            : null;

        // Live counts (always fresh)
        $workerCount = Worker::where('contractor_id', $contractorId)
            ->where('status', 'active')
            ->count();

        if ($settlement) {
            $data = [
                'week_ref'      => $weekRef,
                'worker_count'  => $workerCount,
                'total_pairs'   => $settlement->total_pairs,
                'bata_owes'     => (float) $settlement->bata_owes,
                'workers_paid'  => (float) $settlement->workers_paid,
                'margin'        => (float) $settlement->contractor_margin,
                'status'        => $settlement->settlement_status,
                'source'        => 'calculated',
            ];
        } else {
            // Payroll not yet calculated — derive live from production records
            [$startDate, $endDate] = $this->currentWeekBounds();

            $workerIds = Worker::where('contractor_id', $contractorId)->pluck('id');

            $live = ProductionRecord::whereIn('worker_id', $workerIds)
                ->whereBetween('work_date', [$startDate, $endDate])
                ->whereNotIn('validation_status', ['rejected', 'voided'])
                ->selectRaw('SUM(pairs_produced) as total_pairs, SUM(gross_earnings) as bata_owes')
                ->first();

            $data = [
                'week_ref'     => $weekRef,
                'worker_count' => $workerCount,
                'total_pairs'  => (int) ($live?->total_pairs ?? 0),
                'bata_owes'    => (float) ($live?->bata_owes ?? 0),
                'workers_paid' => null,
                'margin'       => null,
                'status'       => 'pending_payroll',
                'source'       => 'live',
            ];
        }

        return $this->success($data);
    }

    // ── GET /api/contractor/workers ──────────────────────────────────────────

    /**
     * All active workers under this contractor, with this week's payroll figures
     * where available.
     */
    public function workers(): JsonResponse
    {
        $contractorId = $this->contractorId();
        $weekRef      = Carbon::now()->format('o-\WW');
        $run          = WeeklyPayrollRun::where('week_ref', $weekRef)->first();

        $workers = Worker::where('contractor_id', $contractorId)
            ->with(['defaultLine:id,name', 'compliance:worker_id,eobi_registered_at,pessi_registered_at'])
            ->orderBy('name')
            ->get();

        $payrollMap = [];
        if ($run) {
            WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
                ->whereIn('worker_id', $workers->pluck('id'))
                ->get()
                ->each(fn ($wwp) => $payrollMap[$wwp->worker_id] = $wwp);
        }

        $result = $workers->map(function ($worker) use ($payrollMap) {
            $wwp = $payrollMap[$worker->id] ?? null;
            return [
                'id'              => $worker->id,
                'name'            => $worker->name,
                'cnic'            => $worker->cnic,
                'employee_id'     => $worker->biometric_id,
                'grade'           => $worker->grade,
                'default_line'    => $worker->defaultLine?->name,
                'status'          => $worker->status,
                'eobi_registered' => ! empty($worker->eobi_number),
                'pessi_registered'=> ! empty($worker->pessi_number),
                'this_week' => $wwp ? [
                    'gross_earnings' => (float) $wwp->gross_earnings,
                    'total_gross'    => (float) $wwp->total_gross,
                    'net_pay'        => (float) $wwp->net_pay,
                    'payment_status' => $wwp->payment_status,
                ] : null,
            ];
        });

        return $this->success([
            'week_ref' => $weekRef,
            'count'    => $result->count(),
            'workers'  => $result,
        ]);
    }

    // ── GET /api/contractor/settlement/history ───────────────────────────────
    // IMPORTANT: declared before settlement/{weekRef} to prevent route collision

    /**
     * Paginated history of past settlements for this contractor.
     */
    public function settlementHistory(Request $request): JsonResponse
    {
        $contractorId = $this->contractorId();

        $settlements = ContractorSettlement::where('contractor_id', $contractorId)
            ->with('payrollRun:id,week_ref,status,released_at')
            ->orderByDesc('week_ref')
            ->paginate($request->integer('per_page', 20));

        return $this->success($settlements);
    }

    // ── GET /api/contractor/settlement/{weekRef} ─────────────────────────────

    /**
     * Full settlement breakdown for a specific week including per-worker detail.
     * Triggers recalculation if not already stored.
     */
    public function settlement(string $weekRef): JsonResponse
    {
        $contractorId = $this->contractorId();
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        // Ensure settlement is calculated
        $settlement = ContractorSettlement::where('contractor_id', $contractorId)
            ->where('payroll_run_id', $run->id)
            ->first();

        if (! $settlement) {
            if (! in_array($run->status, ['locked', 'paid'])) {
                return $this->error(
                    "Settlement for {$weekRef} is not yet available (run is {$run->status}).",
                    404
                );
            }
            $this->settlementService->calculateSettlement($contractorId, $run->id);
            $settlement = ContractorSettlement::where('contractor_id', $contractorId)
                ->where('payroll_run_id', $run->id)
                ->first();
        }

        // Per-worker breakdown
        $workerIds = Worker::where('contractor_id', $contractorId)->pluck('id');

        $workerPayrolls = WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
            ->whereIn('worker_id', $workerIds)
            ->with('worker:id,name,cnic,biometric_id,default_line_id')
            ->get()
            ->map(fn ($wwp) => [
                'worker_id'      => $wwp->worker_id,
                'name'           => $wwp->worker?->name,
                'cnic'           => $wwp->worker?->cnic,
                'gross_earnings' => (float) $wwp->gross_earnings,
                'total_gross'    => (float) $wwp->total_gross,
                'deductions'     => round(
                    (float) $wwp->advance_deductions
                    + (float) $wwp->rejection_deductions
                    + (float) $wwp->loan_deductions
                    + (float) $wwp->other_deductions,
                    2
                ),
                'net_pay'        => (float) $wwp->net_pay,
                'payment_method' => $wwp->payment_method,
                'payment_status' => $wwp->payment_status,
            ]);

        return $this->success([
            'settlement'      => $settlement,
            'worker_count'    => $workerPayrolls->count(),
            'worker_breakdown'=> $workerPayrolls,
        ]);
    }

    // ── GET /api/contractor/rejections ───────────────────────────────────────

    /**
     * QC rejections against this contractor's workers.
     * Filterable by ?status= and ?from= / ?to= dates.
     */
    public function rejections(Request $request): JsonResponse
    {
        $contractorId = $this->contractorId();
        $workerIds    = Worker::where('contractor_id', $contractorId)->pluck('id');

        $query = QcRejection::whereIn('worker_id', $workerIds)
            ->with(['worker:id,name,biometric_id', 'productionRecord:id,work_date,shift,task,line_id'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'),   fn ($q) => $q->where('work_date', '>=', $request->date('from')))
            ->when($request->filled('to'),     fn ($q) => $q->where('work_date', '<=', $request->date('to')))
            ->orderByDesc('work_date')
            ->orderBy('id');

        $perPage     = min($request->integer('per_page', 25), 100);
        $rejections  = $query->paginate($perPage);

        $summary = [
            'total_pending'   => QcRejection::whereIn('worker_id', $workerIds)->where('status', 'pending')->count(),
            'total_disputed'  => QcRejection::whereIn('worker_id', $workerIds)->where('status', 'disputed')->count(),
            'total_applied'   => QcRejection::whereIn('worker_id', $workerIds)->where('status', 'applied')->count(),
        ];

        return $this->success([
            'summary'    => $summary,
            'rejections' => $rejections,
        ]);
    }

    // ── POST /api/contractor/rejections/{id}/dispute ─────────────────────────

    /**
     * Dispute a QC rejection on behalf of the contractor.
     * Body: { dispute_reason }
     * Only allowed while rejection is still pending or applied (not already reversed).
     */
    public function disputeRejection(Request $request, int $id): JsonResponse
    {
        $contractorId = $this->contractorId();
        $workerIds    = Worker::where('contractor_id', $contractorId)->pluck('id');

        $rejection = QcRejection::whereIn('worker_id', $workerIds)->findOrFail($id);

        if (in_array($rejection->status, ['disputed', 'reversed'])) {
            return $this->error(
                "Rejection is already {$rejection->status} and cannot be disputed again.",
                409
            );
        }

        $data = $request->validate([
            'dispute_reason' => 'required|string|max:1000',
        ]);

        $rejection->update([
            'status'         => 'disputed',
            'disputed_at'    => now(),
            'disputed_by'    => $request->user()?->id,
            'dispute_reason' => $data['dispute_reason'],
        ]);

        return $this->success(
            $rejection->fresh(),
            'Rejection disputed. The QC team will review and respond.'
        );
    }

    // ── GET /api/contractor/compliance ──────────────────────────────────────

    /**
     * EOBI and PESSI registration status for all workers under this contractor.
     * Missing registrations are flagged in the 'missing' bucket.
     */
    public function compliance(): JsonResponse
    {
        $contractorId = $this->contractorId();

        $workers = Worker::where('contractor_id', $contractorId)
            ->where('status', 'active')
            ->with('compliance:worker_id,eobi_registered_at,pessi_registered_at,wht_applicable')
            ->orderBy('name')
            ->get();

        $registered   = [];
        $missingEobi  = [];
        $missingPessi = [];
        $missingBoth  = [];

        foreach ($workers as $worker) {
            $hasEobi  = ! empty($worker->eobi_number);
            $hasPessi = ! empty($worker->pessi_number);

            $entry = [
                'worker_id'   => $worker->id,
                'name'        => $worker->name,
                'cnic'        => $worker->cnic,
                'employee_id' => $worker->biometric_id,
                'eobi_number' => $worker->eobi_number,
                'pessi_number'=> $worker->pessi_number,
                'eobi_registered_at'  => $worker->compliance?->eobi_registered_at,
                'pessi_registered_at' => $worker->compliance?->pessi_registered_at,
            ];

            if ($hasEobi && $hasPessi) {
                $registered[] = $entry;
            } elseif (! $hasEobi && ! $hasPessi) {
                $missingBoth[] = $entry;
            } elseif (! $hasEobi) {
                $missingEobi[] = $entry;
            } else {
                $missingPessi[] = $entry;
            }
        }

        $totalWorkers  = $workers->count();
        $fullyRegistered = count($registered);

        return $this->success([
            'summary' => [
                'total_active'         => $totalWorkers,
                'fully_registered'     => $fullyRegistered,
                'missing_eobi_only'    => count($missingEobi),
                'missing_pessi_only'   => count($missingPessi),
                'missing_both'         => count($missingBoth),
                'compliance_pct'       => $totalWorkers > 0
                    ? round($fullyRegistered / $totalWorkers * 100, 1)
                    : 0,
            ],
            'registered'    => $registered,
            'missing_eobi'  => $missingEobi,
            'missing_pessi' => $missingPessi,
            'missing_both'  => $missingBoth,
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /** Returns the authenticated user's contractor_id (always set — middleware guarantees it). */
    private function contractorId(): int
    {
        return (int) request()->user()->contractor_id;
    }

    /** Monday–Saturday bounds for the current ISO week. */
    private function currentWeekBounds(): array
    {
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);
        return [
            $monday->toDateString(),
            $monday->copy()->addDays(5)->toDateString(),
        ];
    }
}
