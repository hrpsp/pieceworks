<?php

namespace App\Http\Controllers;

use App\Http\Requests\BackfillProductionRequest;
use App\Http\Requests\BatchProductionRequest;
use App\Http\Requests\UpdateProductionRequest;
use App\Models\ProductionRecord;
use App\Services\GhostWorkerService;
use App\Services\RateEngineService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionController extends Controller
{
    public function __construct(
        private readonly RateEngineService  $rateEngine,
        private readonly GhostWorkerService $ghostService,
    ) {}

    /**
     * POST /api/production/batch
     *
     * Submit a session's worth of records (up to 100) in one transaction.
     * Ghost risk is evaluated per record before save:
     *   high   -> record flagged + exception raised + ghost_worker_flag created
     *   medium -> record saved normally with ghost_risk_level=medium warning
     */
    public function batch(BatchProductionRequest $request): JsonResponse
    {
        $records    = $request->validated()['records'];
        $created    = [];
        $failed     = [];
        $ghostFlags = [];

        DB::transaction(function () use ($records, &$created, &$failed, &$ghostFlags) {
            foreach ($records as $i => $row) {
                $workDate = Carbon::parse($row['work_date']);

                // Ghost risk check before saving
                $riskData = $this->ghostService->checkGhostRisk(
                    $row['worker_id'],
                    $workDate,
                    $row['pairs_produced']
                );

                $validationStatus = $riskData['risk_level'] === 'high' ? 'flagged' : 'pending';

                // Rate resolution
                $rateResult = $this->rateEngine->calculateEarnings(
                    workerId:         $row['worker_id'],
                    productionUnitId: $row['production_unit_id'],
                    workDate:         $workDate->toDateString(),
                    pairsProduced:    $row['pairs_produced'],
                    task:             $row['task'],
                    styleSkuId:       $row['style_sku_id'] ?? null
                );

                if (! $rateResult) {
                    $failed[] = [
                        'index'     => $i,
                        'worker_id' => $row['worker_id'],
                        'reason'    => 'No active rate card or matching entry found',
                    ];
                }

                $record = ProductionRecord::create([
                    'worker_id'               => $row['worker_id'],
                    'line_id'                 => $row['line_id'],
                    'work_date'               => $workDate,
                    'shift'                   => $row['shift'],
                    'task'                    => $row['task'],
                    'pairs_produced'          => $row['pairs_produced'],
                    'style_sku_id'            => $row['style_sku_id'] ?? null,
                    'source_tag'              => $row['source_tag'] ?? 'manual_supervisor',
                    'shift_adjustment'        => $row['shift_adjustment'] ?? 0,
                    'shift_adj_authorized_by' => $row['shift_adj_authorized_by'] ?? null,
                    'shift_adj_reason'        => $row['shift_adj_reason'] ?? null,
                    'supervisor_notes'        => $row['supervisor_notes'] ?? null,
                    'rate_card_entry_id'      => $rateResult['rate_card_entry_id'] ?? null,
                    'rate_amount'             => $rateResult['rate_amount'] ?? 0,
                    'gross_earnings'          => $rateResult['earnings'],
                    'wage_model_applied'      => $rateResult['wage_model'],
                    'rate_detail'             => $rateResult['rate_detail'],
                    'validation_status'       => $validationStatus,
                    'ghost_risk_level'        => $riskData['risk_level'],
                    'ghost_flagged_at'        => in_array($riskData['risk_level'], ['medium', 'high']) ? now() : null,
                ]);

                // Raise ghost flag for medium and high risk records
                if (in_array($riskData['risk_level'], ['medium', 'high'])) {
                    $flag = $this->ghostService->raiseFlag(
                        $row['worker_id'],
                        $record->id,
                        $workDate->toDateString(),
                        $riskData
                    );

                    if ($riskData['risk_level'] === 'high') {
                        $this->ghostService->createPayrollException(
                            $row['worker_id'],
                            $record->id,
                            $workDate->toDateString(),
                            $riskData
                        );
                    }

                    $ghostFlags[] = [
                        'index'              => $i,
                        'worker_id'          => $row['worker_id'],
                        'record_id'          => $record->id,
                        'ghost_flag_id'      => $flag->id,
                        'risk_level'         => $riskData['risk_level'],
                        'biometric_present'  => $riskData['biometric_present'],
                        'production_anomaly' => $riskData['production_anomaly'],
                        'held'               => $riskData['risk_level'] === 'high',
                    ];
                }

                $created[] = [
                    'id'         => $record->id,
                    'risk_level' => $riskData['risk_level'],
                    'held'       => $validationStatus === 'flagged',
                ];
            }
        });

        $data = [
            'created_count' => count($created),
            'created'       => $created,
        ];

        if (! empty($failed)) {
            $data['rate_warnings'] = $failed;
        }

        if (! empty($ghostFlags)) {
            $data['ghost_warnings'] = $ghostFlags;
            $data['held_count']     = count(array_filter($ghostFlags, fn($f) => $f['risk_level'] === 'high'));
        }

        return $this->success($data, 'Batch submitted successfully');
    }

    /**
     * GET /api/production/daily
     */
    public function daily(Request $request): JsonResponse
    {
        $request->validate([
            'date'              => ['required', 'date'],
            'line_id'           => ['nullable', 'integer', 'exists:lines,id'],
            'shift'             => ['nullable', 'in:morning,evening,night'],
            'validation_status' => ['nullable', 'in:pending,approved,flagged,rejected'],
        ]);

        $query = ProductionRecord::with(['worker', 'line', 'rateCardEntry', 'styleSku'])
            ->whereDate('work_date', $request->date);

        if ($request->line_id) {
            $query->where('line_id', $request->line_id);
        }
        if ($request->shift) {
            $query->where('shift', $request->shift);
        }
        if ($request->validation_status) {
            $query->where('validation_status', $request->validation_status);
        }

        $records = $query->orderBy('line_id')->orderBy('shift')->orderBy('worker_id')->get();

        return $this->success([
            'date'    => $request->date,
            'summary' => [
                'total_records'  => $records->count(),
                'total_pairs'    => $records->sum('pairs_produced'),
                'total_earnings' => $records->sum('gross_earnings'),
            ],
            'records' => $records,
        ]);
    }

    /**
     * PUT /api/production/{record}
     */
    public function update(UpdateProductionRequest $request, ProductionRecord $record): JsonResponse
    {
        if ($record->is_locked) {
            return $this->error('This record is locked and cannot be edited', 403);
        }

        $record->update($request->validated());

        return $this->success($record->fresh(), 'Production record updated');
    }

    /**
     * POST /api/production/backfill
     */
    public function backfill(BackfillProductionRequest $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'payroll_manager'])) {
            return $this->error('Only admins and payroll managers may backfill records', 403);
        }

        $records = $request->validated()['records'];
        $results = ['created' => [], 'failed' => []];

        foreach ($records as $i => $row) {
            try {
                $workDate = Carbon::parse($row['work_date']);
                $rate     = $this->rateEngine->calculateRate(
                    $row['worker_id'],
                    $row['task'],
                    $row['style_sku_id'] ?? null,
                    $workDate
                );

                $record = ProductionRecord::create([
                    'worker_id'               => $row['worker_id'],
                    'line_id'                 => $row['line_id'],
                    'work_date'               => $workDate,
                    'shift'                   => $row['shift'],
                    'task'                    => $row['task'],
                    'pairs_produced'          => $row['pairs_produced'],
                    'style_sku_id'            => $row['style_sku_id'] ?? null,
                    'source_tag'              => $row['source_tag'] ?? 'manual_backfill',
                    'shift_adjustment'        => $row['shift_adjustment'] ?? 0,
                    'shift_adj_authorized_by' => $row['shift_adj_authorized_by'] ?? null,
                    'shift_adj_reason'        => $row['shift_adj_reason'] ?? null,
                    'supervisor_notes'        => $row['supervisor_notes'] ?? null,
                    'rate_card_entry_id'      => $rate ? $rate['rate_card_entry_id'] : null,
                    'rate_amount'             => $rate ? $rate['rate_amount'] : 0,
                    'gross_earnings'          => $rate ? ($row['pairs_produced'] * $rate['rate_amount']) : 0,
                ]);

                $results['created'][] = ['index' => $i, 'id' => $record->id, 'rate_resolved' => $rate !== null];
            } catch (\Throwable $e) {
                $results['failed'][] = ['index' => $i, 'worker_id' => $row['worker_id'], 'reason' => $e->getMessage()];
            }
        }

        return $this->success($results, 'Backfill completed');
    }

    /**
     * GET /api/production/reconciliation/{date}
     */
    public function reconciliation(Request $request, string $date): JsonResponse
    {
        $workDate = Carbon::parse($date)->startOfDay();

        $records = ProductionRecord::with(['worker:id,name,grade', 'line:id,name'])
            ->whereDate('work_date', $workDate)
            ->get();

        $breakdown = $records->groupBy('line_id')->map(function ($lineRecords) {
            $byShift = $lineRecords->groupBy('shift')->map(fn ($sr) => [
                'worker_count'   => $sr->pluck('worker_id')->unique()->count(),
                'record_count'   => $sr->count(),
                'total_pairs'    => $sr->sum('pairs_produced'),
                'total_earnings' => (float) $sr->sum('gross_earnings'),
            ]);

            return [
                'line_name'           => $lineRecords->first()->line?->name ?? 'Unknown',
                'shifts'              => $byShift,
                'line_total_pairs'    => $lineRecords->sum('pairs_produced'),
                'line_total_earnings' => (float) $lineRecords->sum('gross_earnings'),
            ];
        });

        $pendingFlagged = $records->whereIn('validation_status', ['pending', 'flagged'])
            ->map(fn ($r) => [
                'id'                => $r->id,
                'worker_id'         => $r->worker_id,
                'worker_name'       => $r->worker?->name,
                'line_id'           => $r->line_id,
                'shift'             => $r->shift,
                'task'              => $r->task,
                'pairs_produced'    => $r->pairs_produced,
                'validation_status' => $r->validation_status,
                'ghost_risk_level'  => $r->ghost_risk_level,
                'rate_card_entry_id'=> $r->rate_card_entry_id,
            ])->values();

        $rateCard = $this->rateEngine->resolveRateCard($workDate);

        return $this->success([
            'date'             => $workDate->toDateString(),
            'active_rate_card' => $rateCard ? [
                'id'             => $rateCard->id,
                'version'        => $rateCard->version,
                'effective_date' => $rateCard->effective_date->toDateString(),
            ] : null,
            'totals' => [
                'records'       => $records->count(),
                'pairs'         => $records->sum('pairs_produced'),
                'earnings'      => (float) $records->sum('gross_earnings'),
                'pending_count' => $records->where('validation_status', 'pending')->count(),
                'flagged_count' => $records->where('validation_status', 'flagged')->count(),
                'ghost_high'    => $records->where('ghost_risk_level', 'high')->count(),
                'ghost_medium'  => $records->where('ghost_risk_level', 'medium')->count(),
            ],
            'by_line'      => $breakdown,
            'needs_review' => $pendingFlagged,
        ]);
    }
}
