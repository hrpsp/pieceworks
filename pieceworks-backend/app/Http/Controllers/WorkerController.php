<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkerRequest;
use App\Http\Requests\UpdateWorkerRequest;
use App\Http\Resources\AdvanceCollection;
use App\Http\Resources\ProductionRecordCollection;
use App\Http\Resources\WorkerCollection;
use App\Http\Resources\WorkerResource;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    // ── GET /api/workers ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Worker::with(['contractor', 'defaultLine'])
            ->when($request->filled('contractor_id'), fn ($q) =>
                $q->where('contractor_id', $request->integer('contractor_id'))
            )
            ->when($request->filled('status'), fn ($q) =>
                $q->where('status', $request->string('status'))
            )
            ->when($request->filled('shift'), fn ($q) =>
                $q->where('default_shift', $request->string('shift'))
            )
            ->when($request->filled('search'), fn ($q) =>
                $q->where(function ($inner) use ($request) {
                    $term = '%' . $request->string('search') . '%';
                    $inner->where('name', 'like', $term)
                          ->orWhere('cnic', 'like', $term)
                          ->orWhere('biometric_id', 'like', $term);
                })
            )
            ->orderBy('name');

        $perPage = min($request->integer('per_page', 25), 100);

        return $this->paginated(
            new WorkerCollection($query->paginate($perPage)),
            'Workers retrieved'
        );
    }

    // ── POST /api/workers ───────────────────────────────────────────────────

    public function store(StoreWorkerRequest $request): JsonResponse
    {
        $worker = Worker::create($request->validated());
        $worker->load(['contractor', 'defaultLine']);

        return $this->created(new WorkerResource($worker), 'Worker created successfully');
    }

    // ── GET /api/workers/{worker} ────────────────────────────────────────

    public function show(Worker $worker): JsonResponse
    {
        $worker->load(['contractor', 'defaultLine']);

        return $this->success(new WorkerResource($worker), 'Worker retrieved');
    }

    // ── PUT /api/workers/{worker} ────────────────────────────────────────

    public function update(UpdateWorkerRequest $request, Worker $worker): JsonResponse
    {
        $worker->update($request->validated());
        $worker->load(['contractor', 'defaultLine']);

        return $this->success(new WorkerResource($worker), 'Worker updated successfully');
    }

    // ── DELETE /api/workers/{worker} ─────────────────────────────────────

    public function destroy(Worker $worker): JsonResponse
    {
        $worker->delete();

        return $this->success(null, 'Worker deleted successfully');
    }

    // ── GET /api/workers/{worker}/production-history ─────────────────────

    public function productionHistory(Request $request, Worker $worker): JsonResponse
    {
        $query = $worker->productionRecords()
            ->with(['line', 'styleSku', 'shiftAuthorizer'])
            ->when($request->filled('from'), fn ($q) =>
                $q->where('work_date', '>=', $request->date('from'))
            )
            ->when($request->filled('to'), fn ($q) =>
                $q->where('work_date', '<=', $request->date('to'))
            )
            ->when($request->filled('shift'), fn ($q) =>
                $q->where('shift', $request->string('shift'))
            )
            ->when($request->filled('validation_status'), fn ($q) =>
                $q->where('validation_status', $request->string('validation_status'))
            )
            ->orderByDesc('work_date')
            ->orderByDesc('id');

        $perPage = min($request->integer('per_page', 25), 100);

        return $this->paginated(
            new ProductionRecordCollection($query->paginate($perPage)),
            'Production history retrieved'
        );
    }

    // ── GET /api/workers/{worker}/weekly-summary ─────────────────────────

    public function weeklySummary(Request $request, Worker $worker): JsonResponse
    {
        // Default to the current ISO week; allow ?week=2025-W12 override
        if ($request->filled('week')) {
            $monday = Carbon::now()->setISODate(
                ...array_map('intval', sscanf($request->string('week'), '%d-W%d'))
            )->startOfDay();
        } else {
            $monday = Carbon::now()->startOfWeek(Carbon::MONDAY)->startOfDay();
        }

        $sunday = $monday->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();

        $records = $worker->productionRecords()
            ->whereBetween('work_date', [$monday->toDateString(), $sunday->toDateString()])
            ->selectRaw('
                COUNT(*)                        AS record_count,
                SUM(pairs_produced)             AS total_pairs,
                SUM(gross_earnings)             AS total_gross,
                SUM(shift_adjustment)           AS total_shift_adjustments,
                SUM(CASE WHEN validation_status = "pending"   THEN 1 ELSE 0 END) AS pending_records,
                SUM(CASE WHEN validation_status = "validated" THEN 1 ELSE 0 END) AS validated_records
            ')
            ->first();

        $pendingDeductions = $worker->qcRejections()
            ->whereBetween('work_date', [$monday->toDateString(), $sunday->toDateString()])
            ->where('status', 'pending')
            ->sum('penalty_amount');

        $weekRef = $monday->format('o') . '-W' . $monday->format('W');

        return $this->success([
            'worker_id'    => $worker->id,
            'worker_name'  => $worker->name,
            'week_ref'     => $weekRef,
            'start_date'   => $monday->toDateString(),
            'end_date'     => $sunday->toDateString(),
            'record_count'          => (int) ($records->record_count ?? 0),
            'pending_records'       => (int) ($records->pending_records ?? 0),
            'validated_records'     => (int) ($records->validated_records ?? 0),
            'total_pairs'           => (int) ($records->total_pairs ?? 0),
            'total_gross'           => round((float) ($records->total_gross ?? 0), 2),
            'total_shift_adjustments' => round((float) ($records->total_shift_adjustments ?? 0), 2),
            'pending_qc_deductions' => round((float) $pendingDeductions, 2),
            'estimated_net'         => round(
                (float) ($records->total_gross ?? 0)
                + (float) ($records->total_shift_adjustments ?? 0)
                - (float) $pendingDeductions,
                2
            ),
        ], 'Weekly summary retrieved');
    }

    // ── GET /api/workers/{worker}/advances ───────────────────────────────

    public function advances(Request $request, Worker $worker): JsonResponse
    {
        $query = $worker->advances()
            ->with('approver')
            ->when($request->filled('status'), fn ($q) =>
                $q->where('status', $request->string('status'))
            )
            ->orderByDesc('created_at');

        $perPage = min($request->integer('per_page', 25), 100);

        return $this->paginated(
            new AdvanceCollection($query->paginate($perPage)),
            'Advance history retrieved'
        );
    }

    // ── GET /api/workers/{worker}/shift-adjustments ───────────────────────

    public function shiftAdjustments(Request $request, Worker $worker): JsonResponse
    {
        $query = $worker->productionRecords()
            ->with(['line', 'shiftAuthorizer'])
            ->where('shift_adjustment', '!=', 0)
            ->when($request->filled('from'), fn ($q) =>
                $q->where('work_date', '>=', $request->date('from'))
            )
            ->when($request->filled('to'), fn ($q) =>
                $q->where('work_date', '<=', $request->date('to'))
            )
            ->orderByDesc('work_date')
            ->orderByDesc('id');

        $perPage = min($request->integer('per_page', 25), 100);

        return $this->paginated(
            new ProductionRecordCollection($query->paginate($perPage)),
            'Shift adjustments retrieved'
        );
    }

    // ── GET /api/workers/{worker}/loans ──────────────────────────────────

    public function loans(Worker $worker): JsonResponse
    {
        $loans = $worker->loans()->orderBy('disbursed_at', 'desc')->get();

        return $this->success($loans);
    }

    // ── GET /api/workers/{worker}/compliance ─────────────────────────────

    public function compliance(Worker $worker): JsonResponse
    {
        $compliance = $worker->compliance;

        return $this->success($compliance);
    }
}
