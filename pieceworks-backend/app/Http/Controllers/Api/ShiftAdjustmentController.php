<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShiftAdjustment;
use App\Services\OvertimeService;
use App\Services\ShiftAdjustmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftAdjustmentController extends Controller
{
    public function __construct(
        private ShiftAdjustmentService $adjustmentService,
        private OvertimeService        $overtimeService,
    ) {}

    /**
     * GET /api/shift-adjustments?week_ref=&worker_id=&line_id=
     *
     * List all shift adjustments, filterable by week / worker / line.
     * Includes computed OT summary per worker when week_ref is provided.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'week_ref'  => ['nullable', 'string', 'regex:/^\d{4}-W\d{1,2}$/'],
            'worker_id' => ['nullable', 'integer', 'exists:workers,id'],
            'line_id'   => ['nullable', 'integer', 'exists:lines,id'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = ShiftAdjustment::with([
            'worker:id,name,grade,default_shift',
            'productionRecord:id,pairs_produced,shift,validation_status,ghost_risk_level',
            'line:id,name',
            'authorizer:id,name',
        ])->orderByDesc('work_date');

        if ($weekRef = $request->input('week_ref')) {
            [$year, $isoWeek] = explode('-W', $weekRef);
            $monday   = Carbon::now()->setISODate((int) $year, (int) $isoWeek)->startOfDay();
            $saturday = $monday->copy()->addDays(5);
            $query->whereBetween('work_date', [$monday->toDateString(), $saturday->toDateString()]);
        }

        if ($workerId = $request->integer('worker_id')) {
            $query->where('worker_id', $workerId);
        }

        if ($lineId = $request->integer('line_id')) {
            $query->where('line_id', $lineId);
        }

        $perPage   = $request->integer('per_page', 50);
        $paginated = $query->paginate($perPage);

        // Append per-worker OT summary when filtering by week
        $otSummary = null;
        if ($weekRef && $request->integer('worker_id')) {
            $otSummary = $this->overtimeService->calculateWeeklyOT(
                $request->integer('worker_id'),
                $weekRef
            );
        }

        return $this->success([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
            'ot_summary' => $otSummary,
        ], 'Shift adjustments retrieved.');
    }

    /**
     * GET /api/shift-adjustments/pending
     *
     * Unconfirmed adjustments (confirmed_at IS NULL).
     * Sorted by overtime_flagged desc so urgent OT cases surface first.
     */
    public function pending(Request $request): JsonResponse
    {
        $request->validate([
            'line_id'  => ['nullable', 'integer', 'exists:lines,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = ShiftAdjustment::with([
            'worker:id,name,grade,default_shift',
            'productionRecord:id,pairs_produced,shift,validation_status',
            'line:id,name',
        ])
            ->whereNull('confirmed_at')
            ->orderByDesc('overtime_flagged')
            ->orderByDesc('work_date');

        if ($lineId = $request->integer('line_id')) {
            $query->where('line_id', $lineId);
        }

        $perPage   = $request->integer('per_page', 50);
        $paginated = $query->paginate($perPage);

        return $this->success([
            'data' => $paginated->items(),
            'meta' => [
                'current_page'  => $paginated->currentPage(),
                'last_page'     => $paginated->lastPage(),
                'per_page'      => $paginated->perPage(),
                'total'         => $paginated->total(),
                'ot_flagged_count' => ShiftAdjustment::whereNull('confirmed_at')
                                        ->where('overtime_flagged', true)
                                        ->count(),
            ],
        ], 'Pending shift adjustments retrieved.');
    }

    /**
     * POST /api/shift-adjustments/{id}/confirm
     *
     * Supervisor or payroll manager confirms a pending adjustment.
     * If overtime_flagged, OT premium is written to the production record.
     *
     * Body:
     * {
     *   authorized_by : int     (user id, for audit log)
     *   reason        : string  (free-text explanation, min 10 chars)
     *   reason_code   : string  one of: line_shortage|skill_requirement|worker_request
     * }
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'authorized_by' => ['required', 'integer', 'exists:users,id'],
            'reason'        => ['required', 'string', 'min:10', 'max:1000'],
            'reason_code'   => ['nullable', 'in:line_shortage,skill_requirement,worker_request'],
        ]);

        $adjustment = ShiftAdjustment::findOrFail($id);

        if ($adjustment->confirmed_at !== null) {
            return $this->error('This adjustment has already been confirmed.', 409);
        }

        if (! $adjustment->production_record_id) {
            return $this->error('Adjustment has no linked production record.', 422);
        }

        $confirmed = $this->adjustmentService->confirmAdjustment(
            $adjustment->production_record_id,
            $request->integer('authorized_by'),
            $request->input('reason'),
            $request->input('reason_code', 'line_shortage')
        );

        // If OT was applied, return the updated production record too
        $extra = [];
        if ($confirmed->overtime_flagged) {
            $extra['production_record'] = $confirmed->productionRecord()->first([
                'id', 'pairs_produced', 'rate_amount', 'shift_adjustment', 'gross_earnings',
            ]);
            $extra['ot_premium_applied'] = true;
        }

        return $this->success(
            array_merge($confirmed->toArray(), $extra),
            $confirmed->overtime_flagged
                ? 'Adjustment confirmed. OT premium applied to production record.'
                : 'Adjustment confirmed.'
        );
    }
}
