<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\ApiSyncLog;
use App\Models\BataApiStaging;
use App\Models\ProductionRecord;
use App\Models\StyleSku;
use App\Models\Worker;
use App\Models\WorkerIdMapping;
use App\Services\BataApiService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BataIntegrationController extends Controller
{
    use ApiResponse;

    public function __construct(private BataApiService $bataService) {}

    // ── GET /api/integration/bata/status ────────────────────────────────────

    /**
     * Return the current sync health snapshot.
     */
    public function status(): JsonResponse
    {
        $lastLog = ApiSyncLog::where('sync_type', 'bata_poll')
            ->latest('synced_at')
            ->first();

        $pending = BataApiStaging::whereIn('validation_status', ['held', 'warning', 'error'])
            ->where('processed', false)
            ->count();

        $errors = BataApiStaging::where('validation_status', 'error')
            ->where('processed', false)
            ->count();

        $recentFailures = ApiSyncLog::where('sync_type', 'bata_poll')
            ->whereNotNull('error_message')
            ->where('synced_at', '>=', now()->subHours(2))
            ->count();

        return $this->success([
            'last_sync_at'       => $lastLog?->synced_at,
            'sync_status'        => $lastLog
                ? ($lastLog->error_message ? 'error' : 'ok')
                : 'never_run',
            'last_error'         => $lastLog?->error_message,
            'records_received'   => $lastLog?->records_received ?? 0,
            'records_clean'      => $lastLog?->records_clean    ?? 0,
            'records_held'       => $lastLog?->records_held     ?? 0,
            'records_pending'    => $pending,
            'records_error'      => $errors,
            'consecutive_failures_last_2h' => $recentFailures,
        ]);
    }

    // ── POST /api/integration/bata/sync-now ─────────────────────────────────

    /**
     * Trigger an immediate manual poll (runs synchronously in the request).
     */
    public function syncNow(): JsonResponse
    {
        try {
            $this->bataService->poll();
        } catch (\Throwable $e) {
            return $this->error('Sync failed: ' . $e->getMessage(), 502);
        }

        $log = ApiSyncLog::where('sync_type', 'bata_poll')
            ->latest('synced_at')
            ->first();

        return $this->success([
            'message'          => 'Manual sync completed.',
            'records_received' => $log?->records_received ?? 0,
            'records_clean'    => $log?->records_clean    ?? 0,
            'records_held'     => $log?->records_held     ?? 0,
        ]);
    }

    // ── GET /api/integration/bata/events ────────────────────────────────────

    /**
     * Paginated list of sync event log entries (most recent first).
     * Used by the frontend sync history panel.
     */
    public function events(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $logs = ApiSyncLog::where('sync_type', 'bata_poll')
            ->orderByDesc('synced_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }

    // ── GET /api/integration/bata/staging?date=&status= ─────────────────────

    /**
     * List staging records, optionally filtered by work_date and/or validation_status.
     */
    public function staging(Request $request): JsonResponse
    {
        $query = BataApiStaging::query()
            ->with(['worker:id,name,employee_id', 'line:id,name'])
            ->orderByDesc('work_date')
            ->orderBy('id');

        if ($date = $request->query('date')) {
            $query->whereDate('work_date', $date);
        }

        if ($status = $request->query('status')) {
            $query->where('validation_status', $status);
        }

        $records = $query->paginate(50);

        return $this->success($records);
    }

    // ── POST /api/integration/bata/map-worker ────────────────────────────────

    /**
     * Create (or update) a worker ID mapping.
     * Body: { external_worker_id, pieceworks_worker_id }
     */
    public function mapWorker(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_worker_id'   => 'required|string|max:100',
            'pieceworks_worker_id' => 'required|integer|exists:workers,id',
        ]);

        $sourceSystem = config('services.bata.source_system', 'bata');

        $mapping = WorkerIdMapping::updateOrCreate(
            [
                'external_worker_id' => $data['external_worker_id'],
                'source_system'      => $sourceSystem,
            ],
            [
                'pieceworks_worker_id' => $data['pieceworks_worker_id'],
                'created_by'           => $request->user()?->id,
            ]
        );

        // Re-validate any held staging rows for this external ID so they
        // can be promoted to clean automatically.
        $heldIds = BataApiStaging::where('external_worker_id', $data['external_worker_id'])
            ->whereIn('validation_status', ['held', 'error'])
            ->where('processed', false)
            ->pluck('id');

        foreach ($heldIds as $stagingId) {
            $this->bataService->validateStagingRecord($stagingId);
        }

        $reprocessed = $heldIds->count();

        return $this->created([
            'mapping'      => $mapping,
            'reprocessed'  => $reprocessed,
            'message'      => "Mapping saved. {$reprocessed} held record(s) re-validated.",
        ]);
    }

    // ── GET /api/integration/bata/unmapped-workers ───────────────────────────

    /**
     * Return distinct external_worker_ids that have no mapping yet.
     */
    public function unmappedWorkers(): JsonResponse
    {
        $sourceSystem = config('services.bata.source_system', 'bata');

        $mapped = DB::table('worker_id_mapping')
            ->where('source_system', $sourceSystem)
            ->pluck('external_worker_id');

        $unmapped = BataApiStaging::whereNotIn('external_worker_id', $mapped)
            ->select('external_worker_id')
            ->selectRaw('COUNT(*) as staging_count')
            ->selectRaw('MAX(work_date) as latest_work_date')
            ->groupBy('external_worker_id')
            ->orderByDesc('latest_work_date')
            ->get();

        return $this->success([
            'unmapped_count' => $unmapped->count(),
            'items'          => $unmapped,
        ]);
    }

    // ── POST /api/integration/bata/reconciliation/{date} ────────────────────

    /**
     * Compare Bata API records against manually-entered production records for a date.
     *
     * Returns four buckets:
     *   matched          – same worker + shift + operation in both sources
     *   api_only         – present in bata_api_staging but not production_records
     *   manual_only      – present in production_records (bata_api source excluded) but not staging
     *   count_mismatch   – present in both but pairs_completed differ
     */
    public function reconciliation(Request $request, string $date): JsonResponse
    {
        try {
            $workDate = Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return $this->error('Invalid date format. Use YYYY-MM-DD.', 422);
        }

        // Bata-staged records for the day (processed or not, status = clean|mapped|warning)
        $staged = BataApiStaging::whereDate('work_date', $workDate)
            ->whereIn('validation_status', ['clean', 'mapped', 'warning'])
            ->get()
            ->keyBy(fn ($s) => "{$s->pieceworks_worker_id}_{$s->shift}_{$s->operation}");

        // Manual production records (non-bata) for the day
        $manual = ProductionRecord::whereDate('work_date', $workDate)
            ->where('source_tag', '!=', 'bata_api')
            ->get()
            ->keyBy(fn ($r) => "{$r->worker_id}_{$r->shift}_{$r->task}");

        // Bata production records (source_tag = bata_api) for cross-check
        $bataProduced = ProductionRecord::whereDate('work_date', $workDate)
            ->where('source_tag', 'bata_api')
            ->get()
            ->keyBy(fn ($r) => "{$r->worker_id}_{$r->shift}_{$r->task}");

        $matched        = [];
        $apiOnly        = [];
        $countMismatch  = [];

        foreach ($staged as $key => $s) {
            if (isset($manual[$key])) {
                $manualPairs = $manual[$key]->pairs_produced;
                $apiPairs    = $s->pairs_completed;
                if ($manualPairs !== $apiPairs) {
                    $countMismatch[] = [
                        'key'             => $key,
                        'worker_id'       => $s->pieceworks_worker_id,
                        'shift'           => $s->shift,
                        'operation'       => $s->operation,
                        'api_pairs'       => $apiPairs,
                        'manual_pairs'    => $manualPairs,
                    ];
                } else {
                    $matched[] = $key;
                }
            } elseif (! isset($bataProduced[$key])) {
                $apiOnly[] = [
                    'staging_id'   => $s->id,
                    'worker_id'    => $s->pieceworks_worker_id,
                    'shift'        => $s->shift,
                    'operation'    => $s->operation,
                    'pairs'        => $s->pairs_completed,
                    'status'       => $s->validation_status,
                ];
            }
        }

        $manualOnly = $manual->filter(fn ($r, $key) => ! isset($staged[$key]))->values()
            ->map(fn ($r) => [
                'production_record_id' => $r->id,
                'worker_id'            => $r->worker_id,
                'shift'                => $r->shift,
                'operation'            => $r->task,
                'pairs'                => $r->pairs_produced,
            ]);

        return $this->success([
            'date'            => $workDate,
            'matched_count'   => count($matched),
            'api_only_count'  => count($apiOnly),
            'manual_only_count' => $manualOnly->count(),
            'count_mismatch_count' => count($countMismatch),
            'api_only'        => $apiOnly,
            'manual_only'     => $manualOnly,
            'count_mismatch'  => $countMismatch,
        ]);
    }

    // ── PATCH /api/integration/bata/staging/{id}/accept-api ─────────────────

    /**
     * Accept the API version: promote staging record → ProductionRecord.
     * Any conflicting manual record for the same worker/shift/operation/date is voided.
     */
    public function acceptApi(Request $request, int $id): JsonResponse
    {
        $staging = BataApiStaging::findOrFail($id);

        if ($staging->processed) {
            return $this->error('Staging record is already processed.', 409);
        }

        if (! $staging->pieceworks_worker_id || ! $staging->line_id) {
            return $this->error(
                'Cannot accept: worker or line is not yet mapped. Resolve validation issues first.',
                422
            );
        }

        $styleSku = StyleSku::where('style_code', $staging->style_code)->first();
        if (! $styleSku) {
            return $this->error("Style code '{$staging->style_code}' not found in style_sku table.", 422);
        }

        DB::transaction(function () use ($staging, $styleSku) {
            // Void any conflicting manual records for the same slot
            ProductionRecord::where('worker_id', $staging->pieceworks_worker_id)
                ->whereDate('work_date', $staging->work_date)
                ->where('shift', $staging->shift)
                ->where('task', $staging->operation)
                ->where('source_tag', '!=', 'bata_api')
                ->update(['validation_status' => 'voided']);

            $this->bataService->processCleanRecord(
                $staging,
                $staging->pieceworks_worker_id,
                $staging->line_id,
                $styleSku
            );
        });

        return $this->success(['message' => 'API record accepted and production record created.']);
    }

    // ── PATCH /api/integration/bata/staging/{id}/accept-manual ─────────────

    /**
     * Accept the manual record: discard the staging record (mark it rejected).
     * The existing manual ProductionRecord remains untouched.
     */
    public function acceptManual(Request $request, int $id): JsonResponse
    {
        $staging = BataApiStaging::findOrFail($id);

        if ($staging->processed) {
            return $this->error('Staging record is already processed.', 409);
        }

        $staging->update([
            'processed'         => true,
            'validation_status' => 'rejected',
        ]);

        return $this->success(['message' => 'Manual record accepted. Staging record marked rejected.']);
    }

    // ── PATCH /api/integration/bata/staging/{id}/hold ────────────────────────

    /**
     * Manually place a staging record on hold (or add a note).
     * Body: { reason? }
     */
    public function hold(Request $request, int $id): JsonResponse
    {
        $staging = BataApiStaging::findOrFail($id);

        if ($staging->processed) {
            return $this->error('Staging record is already processed.', 409);
        }

        $reason = $request->input('reason', 'Manually held by admin.');

        $existing = $staging->validation_errors ?? ['errors' => [], 'warnings' => []];
        $existing['errors'][] = "[Admin hold] {$reason}";

        $staging->update([
            'validation_status' => 'held',
            'validation_errors' => $existing,
        ]);

        return $this->success(['message' => 'Record placed on hold.']);
    }
}
