<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advance;
use App\Services\AdvanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvanceController extends Controller
{
    public function __construct(private AdvanceService $advanceService) {}

    /**
     * POST /api/advances
     *
     * Create an advance request. Auto-approved if <= config limit;
     * otherwise requires manager approval.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id'      => ['required', 'integer', 'exists:workers,id'],
            'amount'         => ['required', 'numeric', 'min:100', 'max:99999'],
            'payment_method' => ['nullable', 'in:cash,bank,easypaisa,jazzcash'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ]);

        // Prevent duplicate advance in same week
        $weekRef = Carbon::now()->isoWeekYear() . '-W'
            . str_pad((string) Carbon::now()->isoWeek(), 2, '0', STR_PAD_LEFT);

        $existing = Advance::where('worker_id', $data['worker_id'])
            ->where('week_ref', $weekRef)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($existing) {
            return $this->error("Worker already has an advance for week {$weekRef}.", 409);
        }

        $approval = $this->advanceService->evaluateApproval((float) $data['amount']);

        $advance = Advance::create([
            'worker_id'        => $data['worker_id'],
            'week_ref'         => $weekRef,
            'amount'           => $data['amount'],
            'requires_approval'=> $approval['requires_approval'],
            'approved_by'      => $approval['requires_approval'] ? null : $request->user()->id,
            'approved_at'      => $approval['approved_at'],
            'payment_method'   => $data['payment_method'] ?? 'cash',
            'notes'            => $data['notes'] ?? null,
            'deduction_week'   => Carbon::now()->addWeek()->isoWeekYear() . '-W'
                . str_pad((string) Carbon::now()->addWeek()->isoWeek(), 2, '0', STR_PAD_LEFT),
            'carry_weeks'      => 1,
            'amount_deducted'  => 0,
            'carried_weeks'    => 0,
            'status'           => $approval['status'],
        ]);

        return $this->created(
            $advance->load('worker:id,name,grade'),
            $approval['requires_approval']
                ? 'Advance created. Awaiting manager approval (amount exceeds auto-approval limit).'
                : 'Advance created and auto-approved.'
        );
    }

    /**
     * GET /api/advances?worker_id=&week_ref=&status=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'worker_id' => ['nullable', 'integer'],
            'week_ref'  => ['nullable', 'string', 'regex:/^\d{4}-W\d{1,2}$/'],
            'status'    => ['nullable', 'in:pending,approved,partially_deducted,fully_deducted,cancelled'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Advance::with(['worker:id,name,grade', 'approver:id,name'])
            ->orderByDesc('id');

        if ($workerId = $request->integer('worker_id')) {
            $query->where('worker_id', $workerId);
        }
        if ($weekRef = $request->input('week_ref')) {
            $query->where('week_ref', $weekRef);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $perPage   = $request->integer('per_page', 50);
        $paginated = $query->paginate($perPage);

        return $this->success([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ], 'Advances retrieved.');
    }

    /**
     * PATCH /api/advances/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $advance = Advance::findOrFail($id);

        if ($advance->status !== 'pending') {
            return $this->error("Advance is already '{$advance->status}' and cannot be approved.", 409);
        }

        $advance->update([
            'status'      => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return $this->success(
            $advance->fresh(['worker:id,name', 'approver:id,name']),
            'Advance approved.'
        );
    }
}
