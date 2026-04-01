<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalculatePayrollRequest;
use App\Http\Requests\ResolveExceptionRequest;
use App\Models\PayEdgeHandoffLog;
use App\Models\PayrollException;
use App\Models\PayrollReversal;
use App\Models\ProductionRecord;
use App\Models\WeeklyPayrollRun;
use App\Models\WorkerWeeklyPayroll;
use App\Services\PayEdgeHandoffService;
use App\Services\PayrollCalculationService;
use App\Services\PayrollReversalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollCalculationService $calculator,
        private readonly PayrollReversalService    $reversalService,
        private readonly PayEdgeHandoffService     $payEdgeService,
    ) {}

    // ── GET /api/payroll/current ────────────────────────────────────────────

    /**
     * Return the payroll run for the current ISO week (creates a stub if absent).
     */
    public function current(): JsonResponse
    {
        $weekRef = Carbon::now()->format('o-\WW');

        [$year, $isoWeek] = explode('-W', $weekRef);
        $startDate = Carbon::now()->setISODate((int) $year, (int) $isoWeek)->startOfDay();
        $endDate   = $startDate->copy()->addDays(5); // Saturday

        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->first();

        $stats = null;
        if ($run) {
            $stats = [
                'worker_count'            => $run->workerPayrolls()->count(),
                'exception_count'         => $run->exceptions()->count(),
                'unresolved_exception_count' => $run->exceptions()->whereNull('resolved_at')->count(),
                'total_gross'             => (float) $run->total_gross,
                'total_net'               => (float) $run->total_net,
            ];
        }

        return $this->success([
            'week_ref'   => $weekRef,
            'start_date' => $startDate->toDateString(),
            'end_date'   => $endDate->toDateString(),
            'run'        => $run,
            'stats'      => $stats,
        ]);
    }

    // ── POST /api/payroll/calculate ─────────────────────────────────────────

    /**
     * Trigger (or re-trigger) week calculation.
     * Returns the updated run with summary stats.
     */
    public function calculate(CalculatePayrollRequest $request): JsonResponse
    {
        $weekRef = $request->validated()['week_ref'];

        try {
            $run = $this->calculator->calculateWeek($weekRef);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }

        $run->loadCount(['workerPayrolls', 'exceptions']);

        return $this->success($run, 'Payroll calculated successfully');
    }

    // ── GET /api/payroll/{weekRef} ──────────────────────────────────────────

    /**
     * Full run detail: aggregates + locker/releaser metadata.
     */
    public function show(string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)
            ->with(['locker:id,name', 'releaser:id,name'])
            ->firstOrFail();

        $run->loadCount([
            'workerPayrolls',
            'exceptions',
            'exceptions as unresolved_exceptions_count' => fn ($q) => $q->whereNull('resolved_at'),
        ]);

        return $this->success($run);
    }

    // ── GET /api/payroll/{weekRef}/workers ──────────────────────────────────

    /**
     * Paginated list of all worker payroll lines for the run.
     * Query params: payment_status, per_page (default 50)
     */
    public function workers(Request $request, string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        $request->validate([
            'payment_status' => ['nullable', 'in:pending,processing,paid,failed'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $run->workerPayrolls()
            ->with(['worker:id,name,grade,cnic,contractor_id', 'worker.contractor:id,name'])
            ->orderBy('worker_id');

        if ($request->payment_status) {
            $query->where('payment_status', $request->payment_status);
        }

        return $this->paginated($query->paginate($request->input('per_page', 50)));
    }

    // ── POST /api/payroll/{weekRef}/lock ────────────────────────────────────

    /**
     * Lock the run.
     * Requires: status = open, 0 unresolved exceptions.
     * Side-effect: locks all production records in the week.
     */
    public function lock(Request $request, string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        if ($run->status !== 'open') {
            return $this->error("Run is '{$run->status}' — only open runs can be locked.", 409);
        }

        $unresolvedCount = $run->exceptions()->whereNull('resolved_at')->count();
        if ($unresolvedCount > 0) {
            return $this->error(
                "Cannot lock: {$unresolvedCount} unresolved exception(s) must be resolved first.",
                422
            );
        }

        $run->update([
            'status'    => 'locked',
            'locked_at' => now(),
            'locked_by' => $request->user()->id,
        ]);

        // Lock every production record in this week so they cannot be edited
        ProductionRecord::whereBetween('work_date', [$run->start_date, $run->end_date])
            ->update(['is_locked' => true]);

        return $this->success($run->fresh(['locker:id,name']), 'Payroll run locked');
    }

    // ── POST /api/payroll/{weekRef}/release ─────────────────────────────────

    /**
     * Release payment: transitions run to 'paid', marks all worker lines as 'processing'.
     * Requires: status = locked.
     */
    public function release(Request $request, string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        if ($run->status !== 'locked') {
            return $this->error("Run is '{$run->status}' — only locked runs can be released.", 409);
        }

        $run->workerPayrolls()->update(['payment_status' => 'processing']);

        $run->update([
            'status'      => 'paid',
            'released_at' => now(),
            'released_by' => $request->user()->id,
        ]);

        return $this->success($run->fresh(['releaser:id,name']), 'Payroll released for payment');
    }

    // ── POST /api/payroll/{weekRef}/reverse ─────────────────────────────────

    /**
     * Reverse a paid payroll run — fully or for a single worker.
     *
     * Body: { type: "full"|"partial", worker_id: int (required when partial), reason: string }
     *
     * Full reversal   – run must be 'paid'. Sets run status = 'reversed', marks all
     *                   worker payment records reversed, notifies PayEdge.
     * Partial reversal – run must be 'paid' or 'locked'. Reverses one worker's record
     *                    and creates a carry-forward deduction for the next run.
     *
     * NOTE: Loan and advance ledger balances are NOT automatically reversed.
     * The payroll team must reconcile those manually.
     */
    public function reverse(Request $request, string $weekRef): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return $this->error('Only admins may reverse a payroll run.', 403);
        }

        $data = $request->validate([
            'type'      => ['required', 'in:full,partial'],
            'worker_id' => ['required_if:type,partial', 'nullable', 'integer', 'exists:workers,id'],
            'reason'    => ['required', 'string', 'max:1000'],
        ]);

        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        // ── Dry-run confirmation figures ─────────────────────────────────────
        if ($data['type'] === 'full') {
            $affectedWorkers = WorkerWeeklyPayroll::where('payroll_run_id', $run->id)->count();
            $totalAmount     = (float) WorkerWeeklyPayroll::where('payroll_run_id', $run->id)->sum('net_pay');
        } else {
            $wwp             = WorkerWeeklyPayroll::where('payroll_run_id', $run->id)
                ->where('worker_id', $data['worker_id'])
                ->first();
            $affectedWorkers = $wwp ? 1 : 0;
            $totalAmount     = (float) ($wwp?->net_pay ?? 0);
        }

        try {
            if ($data['type'] === 'full') {
                $reversal = $this->reversalService->reverseFullWeek(
                    $run->id,
                    $data['reason'],
                    $request->user()->id
                );
            } else {
                $reversal = $this->reversalService->reverseWorkerPayment(
                    (int) $data['worker_id'],
                    $run->id,
                    $data['reason'],
                    $request->user()->id
                );
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }

        return $this->success([
            'reversal'         => $reversal,
            'affected_workers' => $affectedWorkers,
            'total_reversed'   => $totalAmount,
            'run_status'       => $run->fresh()->status,
            'note'             => 'Loan and advance ledgers must be manually reconciled before recalculating.',
        ], 'Payroll reversal processed successfully.');
    }

    // ── GET /api/payroll/{weekRef}/reversal-history ──────────────────────────

    /**
     * Return all reversal records for a given payroll run.
     */
    public function reversalHistory(string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        $reversals = PayrollReversal::where('payroll_run_id', $run->id)
            ->with([
                'authorizedBy:id,name',
                'worker:id,name,cnic',
            ])
            ->orderByDesc('created_at')
            ->get();

        return $this->success([
            'run_status'     => $run->status,
            'reversal_count' => $reversals->count(),
            'reversals'      => $reversals,
        ]);
    }

    // ── POST /api/payroll/{weekRef}/payedge-handoff ──────────────────────────

    /**
     * Trigger a PayEdge handoff for all workers in the run.
     * Body: { force?: bool }  — set force=true to re-send already-sent records.
     */
    public function payedgeHandoff(Request $request, string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        $force = (bool) $request->input('force', false);

        try {
            $result = $this->payEdgeService->sendWeeklyHandoff($run->id, $force);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $status = $result['failed'] === 0 ? 'success' : 'partial';

        return $this->success($result, "PayEdge handoff completed ({$status}).");
    }

    // ── GET /api/payroll/{weekRef}/handoff-status ────────────────────────────

    /**
     * Return per-worker PayEdge handoff status for a run.
     * Query: ?status=sent|failed|pending|retrying
     */
    public function handoffStatus(Request $request, string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        $logs = PayEdgeHandoffLog::where('payroll_run_id', $run->id)
            ->with('worker:id,name,cnic,biometric_id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('last_attempted_at')
            ->get();

        $summary = [
            'total'    => $logs->count(),
            'sent'     => $logs->where('status', 'sent')->count(),
            'failed'   => $logs->where('status', 'failed')->count(),
            'retrying' => $logs->where('status', 'retrying')->count(),
            'pending'  => $logs->where('status', 'pending')->count(),
        ];

        return $this->success([
            'week_ref' => $weekRef,
            'summary'  => $summary,
            'records'  => $logs->map(fn ($log) => [
                'worker_id'          => $log->worker_id,
                'worker_name'        => $log->worker?->name,
                'status'             => $log->status,
                'attempts'           => $log->attempts,
                'sent_at'            => $log->sent_at,
                'last_attempted_at'  => $log->last_attempted_at,
                'error_message'      => $log->error_message,
            ]),
        ]);
    }

    // ── GET /api/payroll/{weekRef}/exceptions ───────────────────────────────

    public function exceptions(string $weekRef): JsonResponse
    {
        $run = WeeklyPayrollRun::where('week_ref', $weekRef)->firstOrFail();

        $exceptions = $run->exceptions()
            ->with([
                'worker:id,name,grade',
                'resolver:id,name',
            ])
            ->orderByRaw('resolved_at IS NOT NULL')   // unresolved first
            ->orderBy('exception_type')
            ->get();

        $summary = [
            'total'      => $exceptions->count(),
            'unresolved' => $exceptions->whereNull('resolved_at')->count(),
            'resolved'   => $exceptions->whereNotNull('resolved_at')->count(),
            'by_type'    => $exceptions->groupBy('exception_type')
                                ->map(fn ($g) => $g->count()),
        ];

        return $this->success([
            'run_status' => $run->status,
            'summary'    => $summary,
            'exceptions' => $exceptions,
        ]);
    }

    // ── PATCH /api/payroll/exceptions/{exception}/resolve ───────────────────

    public function resolveException(ResolveExceptionRequest $request, PayrollException $exception): JsonResponse
    {
        if ($exception->resolved_at !== null) {
            return $this->error('This exception has already been resolved.', 409);
        }

        $exception->update([
            'resolved_at'     => now(),
            'resolved_by'     => $request->user()->id,
            'resolution_note' => $request->validated()['resolution_note'],
        ]);

        return $this->success(
            $exception->fresh(['resolver:id,name', 'worker:id,name']),
            'Exception resolved'
        );
    }
}
