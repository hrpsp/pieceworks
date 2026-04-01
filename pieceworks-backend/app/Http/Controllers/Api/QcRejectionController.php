<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deduction;
use App\Models\ProductionRecord;
use App\Models\QcRejection;
use App\Models\WeeklyPayrollRun;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QcRejectionController extends Controller
{
    // ── POST /api/rejections ──────────────────────────────────────────────────

    /**
     * Record a QC rejection against a production record.
     *
     * penalty_mode behaviour:
     *   reduce_pairs  → subtract pairs from production_record (carry-forward deduction if week locked)
     *   flat_penalty  → create a deduction record: pairs × rejection_penalty_per_pair
     *   flag_only     → record only, no financial impact
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'production_record_id' => ['required', 'integer', 'exists:production_records,id'],
            'worker_id'            => ['required', 'integer', 'exists:workers,id'],
            'work_date'            => ['required', 'date'],
            'pairs_rejected'       => ['required', 'integer', 'min:1'],
            'defect_type'          => ['nullable', 'string', 'max:100'],
            'penalty_mode'         => ['required', 'in:reduce_pairs,flat_penalty,flag_only'],
        ]);

        $productionRecord = ProductionRecord::findOrFail($data['production_record_id']);

        // Verify the worker matches the production record
        if ($productionRecord->worker_id !== (int) $data['worker_id']) {
            return $this->error('Worker ID does not match production record.', 422);
        }

        // Cannot reject more pairs than were produced
        if ($data['pairs_rejected'] > $productionRecord->pairs_produced) {
            return $this->error(
                "Cannot reject {$data['pairs_rejected']} pairs — only {$productionRecord->pairs_produced} were produced.",
                422
            );
        }

        $weekRef      = $this->weekRef(Carbon::parse($data['work_date']));
        $payrollRun   = WeeklyPayrollRun::where('week_ref', $weekRef)->first();
        $weekIsLocked = $payrollRun && in_array($payrollRun->status, ['locked', 'paid']);

        $penaltyAmount  = 0.0;
        $pairsDeducted  = 0;
        $carryForwardDeduction = null;

        DB::transaction(function () use (
            $data, $productionRecord, $weekRef, $weekIsLocked, $payrollRun,
            &$penaltyAmount, &$pairsDeducted, &$carryForwardDeduction
        ) {
            switch ($data['penalty_mode']) {

                // ── reduce_pairs ──────────────────────────────────────────
                case 'reduce_pairs':
                    $pairsDeducted = $data['pairs_rejected'];

                    if ($weekIsLocked) {
                        // Week is locked → carry-forward deduction
                        $rateAmount   = (float) $productionRecord->rate_amount;
                        $penaltyAmount = round($pairsDeducted * $rateAmount, 2);

                        $carryForwardDeduction = Deduction::create([
                            'worker_id'         => $data['worker_id'],
                            'payroll_run_id'     => null,
                            'deduction_type_id'  => Deduction::typeId('rejection_penalty'),
                            'amount'             => $penaltyAmount,
                            'reference_id'       => null, // filled after QcRejection is created
                            'reference_type'     => QcRejection::class,
                            'week_ref'           => null, // will be assigned to next open run
                            'carry_from_week'    => $weekRef,
                            'status'             => 'pending',
                        ]);
                    } else {
                        // Not locked: adjust pairs directly on production record
                        $newPairs = $productionRecord->pairs_produced - $pairsDeducted;
                        $productionRecord->updateQuietly([
                            'pairs_produced' => $newPairs,
                            'gross_earnings' => round($newPairs * (float) $productionRecord->rate_amount, 2),
                        ]);
                        $penaltyAmount = round($pairsDeducted * (float) $productionRecord->rate_amount, 2);
                    }
                    break;

                // ── flat_penalty ──────────────────────────────────────────
                case 'flat_penalty':
                    $ratePerPair   = (float) config('payroll.rejection_penalty_per_pair', 5.0);
                    $penaltyAmount = round($data['pairs_rejected'] * $ratePerPair, 2);

                    Deduction::create([
                        'worker_id'        => $data['worker_id'],
                        'payroll_run_id'   => $weekIsLocked ? null : ($payrollRun?->id),
                        'deduction_type_id'=> Deduction::typeId('rejection_penalty'),
                        'amount'           => $penaltyAmount,
                        'reference_id'     => null, // filled after QcRejection created
                        'reference_type'   => QcRejection::class,
                        'week_ref'         => $weekRef,
                        'carry_from_week'  => $weekIsLocked ? $weekRef : null,
                        'status'           => 'pending',
                    ]);
                    break;

                // ── flag_only ─────────────────────────────────────────────
                case 'flag_only':
                    // No financial impact — just record for QC review
                    break;
            }
        });

        // Create the QcRejection row (outside the inner transaction so IDs are available)
        $rejection = QcRejection::create([
            'production_record_id' => $data['production_record_id'],
            'worker_id'            => $data['worker_id'],
            'work_date'            => $data['work_date'],
            'pairs_rejected'       => $data['pairs_rejected'],
            'defect_type'          => $data['defect_type'] ?? null,
            'penalty_mode'         => $data['penalty_mode'],
            'penalty_type'         => 'per_pair', // legacy column default
            'penalty_amount'       => $penaltyAmount,
            'pairs_deducted'       => $pairsDeducted,
            'status'               => 'pending',
        ]);

        // Back-fill reference_id on any deductions just created
        if ($carryForwardDeduction) {
            $carryForwardDeduction->update(['reference_id' => $rejection->id]);
        }
        // For flat_penalty, update the most recent pending deduction for this worker/week
        if ($data['penalty_mode'] === 'flat_penalty') {
            Deduction::where('worker_id', $data['worker_id'])
                ->where('reference_type', QcRejection::class)
                ->whereNull('reference_id')
                ->latest()
                ->limit(1)
                ->update(['reference_id' => $rejection->id]);
        }

        $responseData = ['rejection' => $rejection];
        $message = 'QC rejection recorded.';

        if ($data['penalty_mode'] === 'reduce_pairs' && $weekIsLocked) {
            $responseData['carry_forward'] = true;
            $responseData['deduction_amount'] = $penaltyAmount;
            $message = 'QC rejection recorded. Week is locked — carry-forward deduction created.';
        }

        return $this->created($responseData, $message);
    }

    // ── GET /api/rejections ───────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'week_ref'  => ['nullable', 'string', 'regex:/^\d{4}-W\d{1,2}$/'],
            'worker_id' => ['nullable', 'integer', 'exists:workers,id'],
            'status'    => ['nullable', 'in:pending,applied,disputed,reversed'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = QcRejection::with([
            'worker:id,name,grade',
            'productionRecord:id,shift,task,line_id,validation_status',
            'resolver:id,name',
        ])->orderByDesc('work_date');

        if ($weekRef = $request->input('week_ref')) {
            [$start, $end] = $this->weekBoundsFromRef($weekRef);
            $query->whereBetween('work_date', [$start, $end]);
        }

        if ($workerId = $request->integer('worker_id')) {
            $query->where('worker_id', $workerId);
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
        ], 'Rejections retrieved.');
    }

    // ── GET /api/rejections/pending-qc ────────────────────────────────────────

    public function pendingQc(Request $request): JsonResponse
    {
        $query = QcRejection::with([
            'worker:id,name,grade',
            'productionRecord:id,shift,task,line_id,pairs_produced',
        ])
            ->where('status', 'pending')
            ->orderByDesc('work_date');

        $paginated = $query->paginate($request->integer('per_page', 50));

        return $this->success([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ], 'Pending QC rejections retrieved.');
    }

    // ── PATCH /api/rejections/{id}/dispute ────────────────────────────────────

    public function dispute(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'dispute_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $rejection = QcRejection::findOrFail($id);

        if (! in_array($rejection->status, ['pending', 'applied'])) {
            return $this->error("Cannot dispute a rejection with status '{$rejection->status}'.", 409);
        }

        $rejection->update([
            'status'        => 'disputed',
            'disputed_at'   => now(),
            'dispute_reason'=> $request->input('dispute_reason'),
            'disputed_by'   => $request->user()->id,
        ]);

        // QcRejectionObserver::updated() fires here and creates the PayrollException notification

        return $this->success(
            $rejection->fresh(['worker', 'productionRecord']),
            'Rejection disputed. QC supervisor has been notified.'
        );
    }

    // ── PATCH /api/rejections/{id}/resolve ────────────────────────────────────

    public function resolve(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'resolution' => ['required', 'in:accept,reverse'],
            'notes'      => ['nullable', 'string', 'max:2000'],
        ]);

        $rejection = QcRejection::findOrFail($id);

        if ($rejection->status !== 'disputed') {
            return $this->error("Only disputed rejections can be resolved. Current status: '{$rejection->status}'.", 409);
        }

        $resolution = $request->input('resolution');

        DB::transaction(function () use ($rejection, $resolution, $request) {
            if ($resolution === 'reverse') {
                $this->reverseFinancialImpact($rejection);
            }

            $rejection->update([
                'status'           => $resolution === 'reverse' ? 'reversed' : 'applied',
                'resolution'       => $resolution,
                'resolution_notes' => $request->input('notes'),
                'resolved_at'      => now(),
                'resolved_by'      => $request->user()->id,
            ]);
        });

        // QcRejectionObserver::updated() fires and logs the reversal to audit_logs

        $message = $resolution === 'reverse'
            ? 'Rejection reversed. Financial impact undone (credit created if deduction already applied).'
            : 'Rejection accepted. No financial change.';

        return $this->success($rejection->fresh(['worker', 'productionRecord', 'resolver']), $message);
    }

    // ── GET /api/rejections/analysis ─────────────────────────────────────────

    /**
     * Rejection rate analysis across multiple dimensions.
     *
     * Query params:
     *   week_ref     ISO week to analyse (or date_from/date_to for a range)
     *   date_from    Y-m-d
     *   date_to      Y-m-d
     *   group_by     worker|task|defect_type|line  (default: worker)
     *   worker_id    optional filter
     *   line_id      optional filter
     */
    public function analysis(Request $request): JsonResponse
    {
        $request->validate([
            'week_ref'   => ['nullable', 'string', 'regex:/^\d{4}-W\d{1,2}$/'],
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date', 'after_or_equal:date_from'],
            'group_by'   => ['nullable', 'in:worker,task,defect_type,line'],
            'worker_id'  => ['nullable', 'integer'],
            'line_id'    => ['nullable', 'integer'],
        ]);

        // Resolve date range
        if ($weekRef = $request->input('week_ref')) {
            [$dateFrom, $dateTo] = $this->weekBoundsFromRef($weekRef);
        } else {
            $dateFrom = $request->input('date_from', Carbon::now()->startOfWeek()->toDateString());
            $dateTo   = $request->input('date_to',   Carbon::now()->toDateString());
        }

        $groupBy = $request->input('group_by', 'worker');

        // Base rejection query
        $rejectQuery = DB::table('qc_rejections as qr')
            ->join('production_records as pr', 'pr.id', '=', 'qr.production_record_id')
            ->join('workers as w', 'w.id', '=', 'qr.worker_id')
            ->leftJoin('lines as l', 'l.id', '=', 'pr.line_id')
            ->whereBetween('qr.work_date', [$dateFrom, $dateTo])
            ->whereNotIn('qr.status', ['reversed']);

        if ($workerId = $request->integer('worker_id')) {
            $rejectQuery->where('qr.worker_id', $workerId);
        }
        if ($lineId = $request->integer('line_id')) {
            $rejectQuery->where('pr.line_id', $lineId);
        }

        // Build group-by clause
        $rows = match ($groupBy) {
            'worker' => $rejectQuery
                ->selectRaw('qr.worker_id, w.name as worker_name, w.grade,
                    SUM(qr.pairs_rejected) as total_rejected,
                    SUM(qr.penalty_amount) as total_penalty,
                    COUNT(qr.id) as rejection_count,
                    SUM(pr.pairs_produced) as total_produced')
                ->groupBy('qr.worker_id', 'w.name', 'w.grade')
                ->orderByDesc('total_rejected')
                ->get(),

            'task' => $rejectQuery
                ->selectRaw('pr.task,
                    SUM(qr.pairs_rejected) as total_rejected,
                    SUM(qr.penalty_amount) as total_penalty,
                    COUNT(qr.id) as rejection_count,
                    SUM(pr.pairs_produced) as total_produced')
                ->groupBy('pr.task')
                ->orderByDesc('total_rejected')
                ->get(),

            'defect_type' => $rejectQuery
                ->selectRaw('COALESCE(qr.defect_type, "unspecified") as defect_type,
                    SUM(qr.pairs_rejected) as total_rejected,
                    SUM(qr.penalty_amount) as total_penalty,
                    COUNT(qr.id) as rejection_count')
                ->groupBy('defect_type')
                ->orderByDesc('total_rejected')
                ->get(),

            'line' => $rejectQuery
                ->selectRaw('pr.line_id, l.name as line_name,
                    SUM(qr.pairs_rejected) as total_rejected,
                    SUM(qr.penalty_amount) as total_penalty,
                    COUNT(qr.id) as rejection_count,
                    SUM(pr.pairs_produced) as total_produced')
                ->groupBy('pr.line_id', 'l.name')
                ->orderByDesc('total_rejected')
                ->get(),
        };

        // Add rejection rate % to rows that have production totals
        $rows = $rows->map(function ($row) {
            $row = (array) $row;
            if (isset($row['total_produced']) && $row['total_produced'] > 0) {
                $row['rejection_rate_pct'] = round(
                    ($row['total_rejected'] / $row['total_produced']) * 100, 2
                );
            }
            return $row;
        });

        // Overall summary
        $summary = DB::table('qc_rejections')
            ->whereBetween('work_date', [$dateFrom, $dateTo])
            ->whereNotIn('status', ['reversed'])
            ->selectRaw('
                COUNT(*) as total_rejections,
                SUM(pairs_rejected) as total_pairs_rejected,
                SUM(penalty_amount) as total_penalty_pkr,
                SUM(CASE WHEN status = "disputed" THEN 1 ELSE 0 END) as disputed_count,
                SUM(CASE WHEN penalty_mode = "reduce_pairs" THEN 1 ELSE 0 END) as reduce_pairs_count,
                SUM(CASE WHEN penalty_mode = "flat_penalty" THEN 1 ELSE 0 END) as flat_penalty_count,
                SUM(CASE WHEN penalty_mode = "flag_only" THEN 1 ELSE 0 END) as flag_only_count
            ')
            ->first();

        return $this->success([
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'group_by'   => $groupBy,
            'summary'    => $summary,
            'breakdown'  => $rows->values(),
        ], 'Rejection analysis retrieved.');
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Reverse the financial impact of a rejection on resolution = 'reverse'.
     *
     * reduce_pairs:
     *   - If production week NOT locked: restore pairs on production_record
     *   - If locked (deduction was applied): create a credit deduction for next week
     *
     * flat_penalty:
     *   - Find the linked Deduction record
     *   - If pending: delete it
     *   - If applied: create a negative-amount (credit) deduction carry-forward
     *
     * flag_only: nothing to reverse
     */
    private function reverseFinancialImpact(QcRejection $rejection): void
    {
        $weekRef    = $this->weekRef(Carbon::parse($rejection->work_date));
        $payrollRun = WeeklyPayrollRun::where('week_ref', $weekRef)->first();
        $weekLocked = $payrollRun && in_array($payrollRun->status, ['locked', 'paid']);

        switch ($rejection->penalty_mode) {

            case 'reduce_pairs':
                $record = ProductionRecord::find($rejection->production_record_id);
                if (! $record) {
                    break;
                }

                if (! $weekLocked) {
                    // Restore pairs directly
                    $restored = $record->pairs_produced + $rejection->pairs_deducted;
                    $record->updateQuietly([
                        'pairs_produced' => $restored,
                        'gross_earnings' => round($restored * (float) $record->rate_amount, 2),
                    ]);
                } else {
                    // Week is locked — create a credit deduction (positive for worker)
                    // that will offset the next payroll run's rejection_deductions
                    Deduction::create([
                        'worker_id'        => $rejection->worker_id,
                        'payroll_run_id'   => null,
                        'deduction_type_id'=> Deduction::typeId('rejection_penalty'),
                        'amount'           => -1 * abs($rejection->penalty_amount), // negative = credit
                        'reference_id'     => $rejection->id,
                        'reference_type'   => QcRejection::class,
                        'week_ref'         => null,
                        'carry_from_week'  => $weekRef,
                        'status'           => 'pending',
                    ]);
                    $rejection->credit_created = true; // will be saved by caller
                }
                break;

            case 'flat_penalty':
                $deduction = Deduction::where('reference_type', QcRejection::class)
                    ->where('reference_id', $rejection->id)
                    ->where('amount', '>', 0)
                    ->latest()
                    ->first();

                if (! $deduction) {
                    break;
                }

                if ($deduction->status === 'pending') {
                    $deduction->delete();
                } else {
                    // Already applied → issue credit for next run
                    Deduction::create([
                        'worker_id'        => $rejection->worker_id,
                        'payroll_run_id'   => null,
                        'deduction_type_id'=> Deduction::typeId('rejection_penalty'),
                        'amount'           => -1 * abs($deduction->amount),
                        'reference_id'     => $rejection->id,
                        'reference_type'   => QcRejection::class,
                        'week_ref'         => null,
                        'carry_from_week'  => $deduction->week_ref,
                        'status'           => 'pending',
                    ]);
                    $rejection->credit_created = true;
                }
                break;
        }
    }

    /**
     * Convert a Carbon date to its ISO week reference string (YYYY-WNN).
     */
    private function weekRef(Carbon $date): string
    {
        return $date->isoWeekYear() . '-W' . str_pad((string) $date->isoWeek(), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Return [dateFrom, dateTo] strings for an ISO week ref.
     */
    private function weekBoundsFromRef(string $weekRef): array
    {
        [$year, $isoWeek] = explode('-W', $weekRef);
        $monday   = Carbon::now()->setISODate((int) $year, (int) $isoWeek)->startOfDay();
        $saturday = $monday->copy()->addDays(5);
        return [$monday->toDateString(), $saturday->toDateString()];
    }
}
