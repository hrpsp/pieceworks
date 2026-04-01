<?php

namespace App\Services;

use App\Models\ApiSyncLog;
use App\Models\BataApiStaging;
use App\Models\Line;
use App\Models\ProductionRecord;
use App\Models\StyleSku;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BataApiService
{
    public function __construct(private RateEngineService $rateEngine) {}

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Fetch production records from the Bata API and stage them for validation.
     *
     * Flow:
     *   1. GET configured endpoint with bearer auth.
     *   2. Deduplicate against existing staging rows (same external_worker_id + work_date + shift + operation).
     *   3. Insert new records into bata_api_staging.
     *   4. Validate each inserted record.
     *   5. Write ApiSyncLog entry (success or failure).
     *
     * @throws \RuntimeException  Re-thrown after logging so the caller can count consecutive failures.
     */
    public function poll(): void
    {
        $syncedAt = now();
        $received = 0;
        $clean    = 0;
        $held     = 0;

        try {
            $endpoint = config('services.bata.endpoint');
            $apiKey   = config('services.bata.api_key');
            $timeout  = (int) config('services.bata.timeout', 30);

            if (empty($endpoint)) {
                throw new \RuntimeException('BATA_API_ENDPOINT is not configured.');
            }

            $response = Http::timeout($timeout)
                ->withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->acceptJson()
                ->get($endpoint);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    "Bata API returned HTTP {$response->status()}: " . mb_substr($response->body(), 0, 500)
                );
            }

            $payload = $response->json();
            $records = $payload['records'] ?? (array_is_list($payload) ? $payload : []);

            if (empty($records)) {
                ApiSyncLog::create([
                    'sync_type'        => 'bata_poll',
                    'records_received' => 0,
                    'records_clean'    => 0,
                    'records_held'     => 0,
                    'error_message'    => null,
                    'synced_at'        => $syncedAt,
                ]);
                return;
            }

            $received = count($records);

            DB::transaction(function () use ($records, &$clean, &$held) {
                foreach ($records as $rec) {
                    $externalId = (string) ($rec['worker_id'] ?? $rec['external_worker_id'] ?? '');
                    $workDate   = $rec['work_date'] ?? null;
                    $shift      = $rec['shift']     ?? null;
                    $operation  = $rec['operation'] ?? $rec['task'] ?? '';

                    if (empty($externalId) || empty($workDate) || empty($shift)) {
                        continue; // skip malformed rows
                    }

                    // Idempotency: skip if already staged
                    $alreadyStaged = BataApiStaging::where('external_worker_id', $externalId)
                        ->where('work_date', $workDate)
                        ->where('shift', $shift)
                        ->where('operation', $operation)
                        ->exists();

                    if ($alreadyStaged) {
                        continue;
                    }

                    $staging = BataApiStaging::create([
                        'external_worker_id' => $externalId,
                        'pieceworks_worker_id'=> null,
                        'line_id'            => null,
                        'style_code'         => $rec['style_code']      ?? '',
                        'operation'          => $operation,
                        'pairs_completed'    => (int) ($rec['pairs_completed'] ?? 0),
                        'pairs_rejected'     => (int) ($rec['pairs_rejected']  ?? 0),
                        'work_date'          => $workDate,
                        'shift'              => $shift,
                        'raw_payload'        => $rec,
                        'source_tag'         => 'bata_api',
                        'validation_status'  => 'held',
                        'processed'          => false,
                    ]);

                    $this->validateStagingRecord($staging->id);

                    $staging->refresh();
                    match ($staging->validation_status) {
                        'clean'  => $clean++,
                        default  => $held++,
                    };
                }
            });

            ApiSyncLog::create([
                'sync_type'        => 'bata_poll',
                'records_received' => $received,
                'records_clean'    => $clean,
                'records_held'     => $held,
                'error_message'    => null,
                'synced_at'        => $syncedAt,
            ]);

        } catch (\Throwable $e) {
            Log::error('BataApiService::poll failed', [
                'error'     => $e->getMessage(),
                'received'  => $received,
            ]);

            ApiSyncLog::create([
                'sync_type'        => 'bata_poll',
                'records_received' => $received,
                'records_clean'    => 0,
                'records_held'     => 0,
                'error_message'    => $e->getMessage(),
                'synced_at'        => $syncedAt,
            ]);

            throw $e; // re-throw so BataSyncCommand can count consecutive failures
        }
    }

    /**
     * Validate a single staging record and update its validation_status.
     *
     * Statuses:
     *   clean   – all checks pass; production record created automatically.
     *   warning – non-blocking issues (e.g. line unresolvable); needs review.
     *   error   – blocking data quality (bad pairs, unknown style, future date).
     *   held    – admin action required (unmapped worker, locked week, duplicate).
     */
    public function validateStagingRecord(int $stagingId): void
    {
        $staging = BataApiStaging::findOrFail($stagingId);
        $errors   = [];
        $warnings = [];

        $workerId = null;
        $lineId   = null;
        $styleSku = null;
        $sourceSystem = config('services.bata.source_system', 'bata');

        // ── 1. Worker mapping ───────────────────────────────────────────────
        $mapping = DB::table('worker_id_mapping')
            ->where('external_worker_id', $staging->external_worker_id)
            ->where('source_system', $sourceSystem)
            ->first();

        if (! $mapping) {
            $errors[] = "No worker mapping for external_worker_id '{$staging->external_worker_id}'. "
                      . 'Use POST /api/integration/bata/map-worker to register.';
        } else {
            $worker = Worker::find($mapping->pieceworks_worker_id);
            if ($worker) {
                $workerId = $worker->id;
            } else {
                $errors[] = "Mapped pieceworks_worker_id {$mapping->pieceworks_worker_id} no longer exists.";
            }
        }

        // ── 2. Line resolution (from raw_payload) ──────────────────────────
        $raw      = $staging->raw_payload ?? [];
        $lineHint = $raw['line_code'] ?? $raw['line_name'] ?? $raw['line'] ?? null;

        if ($lineHint) {
            $line = Line::where('name', $lineHint)
                ->orWhere('id', is_numeric($lineHint) ? $lineHint : null)
                ->first();

            if ($line) {
                $lineId = $line->id;
            } else {
                $warnings[] = "Line '{$lineHint}' from payload not found in lines table. Manual assignment required.";
            }
        } else {
            $warnings[] = 'No line identifier in payload. Manual line assignment required.';
        }

        // ── 3. Style code ───────────────────────────────────────────────────
        if (! empty($staging->style_code)) {
            $styleSku = StyleSku::where('style_code', $staging->style_code)->first();
            if (! $styleSku) {
                $errors[] = "Style code '{$staging->style_code}' not found in style_sku table.";
            }
        } else {
            $errors[] = 'Missing style_code in staging record.';
        }

        // ── 4. Pairs range ──────────────────────────────────────────────────
        if ($staging->pairs_completed < 0 || $staging->pairs_completed > 200) {
            $errors[] = "pairs_completed ({$staging->pairs_completed}) is outside the valid range 0–200.";
        }

        // ── 5. Date not future ──────────────────────────────────────────────
        $workDate = Carbon::parse($staging->work_date);
        if ($workDate->isAfter(Carbon::today())) {
            $errors[] = "work_date {$staging->work_date} is in the future.";
        }

        // ── 6. Work week not locked ─────────────────────────────────────────
        $weekRef   = $workDate->isoWeekYear() . '-W'
                   . str_pad((string) $workDate->isoWeek(), 2, '0', STR_PAD_LEFT);
        $isLocked  = WeeklyPayrollRun::where('week_ref', $weekRef)
            ->whereIn('status', ['locked', 'paid'])
            ->exists();

        if ($isLocked) {
            $errors[] = "Week {$weekRef} (work_date {$staging->work_date}) is locked. Cannot accept new records.";
        }

        // ── 7. Duplicate detection ──────────────────────────────────────────
        if ($workerId) {
            $duplicate = ProductionRecord::where('worker_id', $workerId)
                ->whereDate('work_date', $workDate)
                ->where('shift', $staging->shift)
                ->where('task', $staging->operation)
                ->where('source_tag', 'bata_api')
                ->exists();

            if ($duplicate) {
                $errors[] = "Duplicate: worker {$workerId} already has a bata_api record for "
                          . "{$staging->work_date} / {$staging->shift} / {$staging->operation}.";
            }
        }

        // ── Determine final status ──────────────────────────────────────────
        // Locked week and duplicates → held (admin must act)
        // Other errors → error (bad data)
        // Warnings only → warning
        // All pass → clean
        $heldTriggers = ['locked', 'duplicate', 'No worker mapping'];
        $isHeld = ! empty($errors) && collect($errors)->contains(
            fn ($e) => collect($heldTriggers)->contains(fn ($t) => str_contains($e, $t))
        );

        if (! empty($errors)) {
            $status = $isHeld ? 'held' : 'error';
        } elseif (! empty($warnings)) {
            $status = 'warning';
        } else {
            $status = 'clean';
        }

        $updateData = [
            'validation_status' => $status,
            'validation_errors' => (! empty($errors) || ! empty($warnings))
                ? ['errors' => $errors, 'warnings' => $warnings]
                : null,
        ];

        if ($workerId !== null) {
            $updateData['pieceworks_worker_id'] = $workerId;
        }
        if ($lineId !== null) {
            $updateData['line_id'] = $lineId;
        }

        $staging->update($updateData);

        // ── Auto-process clean records ──────────────────────────────────────
        if ($status === 'clean' && $workerId && $lineId && $styleSku) {
            $this->processCleanRecord($staging->fresh(), $workerId, $lineId, $styleSku);
        }
    }

    /**
     * Create a ProductionRecord from a clean staging row and mark it processed.
     * Called automatically by validateStagingRecord for clean records,
     * and manually by BataIntegrationController::acceptApi().
     */
    public function processCleanRecord(BataApiStaging $staging, int $workerId, int $lineId, StyleSku $styleSku): void
    {
        $workDate = Carbon::parse($staging->work_date);

        $rate = $this->rateEngine->calculateRate(
            $workerId,
            $staging->operation,
            $styleSku->id,
            $workDate
        );

        ProductionRecord::create([
            'worker_id'          => $workerId,
            'line_id'            => $lineId,
            'work_date'          => $workDate,
            'shift'              => $staging->shift,
            'task'               => $staging->operation,
            'pairs_produced'     => $staging->pairs_completed,
            'style_sku_id'       => $styleSku->id,
            'source_tag'         => 'bata_api',
            'rate_card_entry_id' => $rate ? $rate['rate_card_entry_id'] : null,
            'rate_amount'        => $rate ? $rate['rate_amount']        : 0,
            'gross_earnings'     => $rate ? $staging->pairs_completed * $rate['rate_amount'] : 0,
            'validation_status'  => 'pending',
        ]);

        $staging->update([
            'processed'         => true,
            'validation_status' => 'mapped',
        ]);
    }
}
