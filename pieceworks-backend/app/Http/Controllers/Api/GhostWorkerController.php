<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GhostWorkerFlag;
use App\Services\GhostWorkerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GhostWorkerController extends Controller
{
    public function __construct(private GhostWorkerService $ghostService) {}

    /**
     * GET /api/ghost-worker/flags
     *
     * Returns all unresolved ghost flags, most recent first.
     * Query params: risk_level, worker_id, date_from, date_to, resolved (bool)
     */
    public function flags(Request $request): JsonResponse
    {
        $request->validate([
            'risk_level' => ['nullable', 'in:medium,high'],
            'worker_id'  => ['nullable', 'integer'],
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date', 'after_or_equal:date_from'],
            'resolved'   => ['nullable', 'in:0,1,true,false'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = GhostWorkerFlag::with([
            'worker:id,name,grade,biometric_id',
            'productionRecord:id,pairs_produced,shift,validation_status,ghost_risk_level',
            'overriddenBy:id,name',
        ])->orderByDesc('work_date');

        if ($request->risk_level) {
            $query->where('risk_level', $request->risk_level);
        }

        if ($request->worker_id) {
            $query->where('worker_id', $request->worker_id);
        }

        if ($request->date_from) {
            $query->where('work_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->where('work_date', '<=', $request->date_to);
        }

        // Default: only unresolved flags
        $showResolved = filter_var($request->input('resolved', false), FILTER_VALIDATE_BOOLEAN);
        if ($showResolved) {
            $query->whereNotNull('resolved_at');
        } else {
            $query->whereNull('resolved_at');
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
        ], 'Ghost worker flags retrieved.');
    }

    /**
     * POST /api/ghost-worker/{id}/override
     *
     * Authorised payroll manager / admin clears a ghost flag.
     * Restores the held production record to pending.
     * Requires permission: ghost_worker.override (enforced via route middleware).
     *
     * Body: { reason: string, authorized_by: int (user id, for log only) }
     */
    public function override(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason'        => ['required', 'string', 'min:10', 'max:1000'],
            'authorized_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $flag = GhostWorkerFlag::findOrFail($id);

        if ($flag->resolved_at !== null) {
            return $this->error('This ghost worker flag has already been resolved.', 409);
        }

        $actingUserId = $request->user()->id;

        $this->ghostService->overrideFlag($flag, $actingUserId, $request->reason);

        // Audit entry written automatically via AuditObserver on GhostWorkerFlag model.
        // Additional explicit log for the manual override action:
        \DB::table('audit_logs')->insert([
            'user_id'    => $actingUserId,
            'action'     => 'ghost_worker_override',
            'model_type' => GhostWorkerFlag::class,
            'model_id'   => $flag->id,
            'old_values' => json_encode(['risk_level' => $flag->risk_level, 'resolved_at' => null]),
            'new_values' => json_encode([
                'override_reason' => $request->reason,
                'overridden_by'   => $actingUserId,
                'authorized_by'   => $request->authorized_by,
            ]),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->success($flag->fresh(['worker', 'productionRecord', 'overriddenBy']), 'Ghost worker flag overridden. Production record restored to pending.');
    }
}
