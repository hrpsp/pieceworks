<?php

namespace App\Observers;

use App\Models\Line;
use App\Models\ProductionRecord;
use App\Services\RateEngineService;
use App\Services\ShiftAdjustmentService;
use Illuminate\Support\Facades\Log;

class ProductionRecordObserver
{
    public function __construct(
        private readonly RateEngineService      $rateEngine,
        private readonly ShiftAdjustmentService $shiftAdjustmentService,
    ) {}

    /**
     * Fires before a ProductionRecord is inserted.
     *
     * Sets billing_contractor_id to the line's default contractor.
     * When a worker from Contractor A produces on a line belonging to Contractor B,
     * billing_contractor_id = B so Bata's settlement with B includes those pairs.
     */
    public function creating(ProductionRecord $record): void
    {
        if ($record->billing_contractor_id === null && $record->line_id) {
            $line = Line::select('id', 'default_contractor_id')->find($record->line_id);
            $record->billing_contractor_id = $line?->default_contractor_id;
        }
    }

    /**
     * Fires after a ProductionRecord is created.
     *
     * 1. Rate resolution (skip if pre-calculated by batch endpoint).
     * 2. Shift adjustment detection — creates a shift_adjustments row when
     *    the worker's actual shift differs from schedule, or gap < 8h.
     */
    public function created(ProductionRecord $record): void
    {
        // ── 1. Rate resolution ──────────────────────────────────────────────
        if ($record->rate_card_entry_id === null) {
            $result = $this->rateEngine->calculateRate(
                $record->worker_id,
                $record->task,
                $record->style_sku_id,
                $record->work_date
            );

            if (! $result) {
                Log::warning('RateEngine: no rate found', [
                    'production_record_id' => $record->id,
                    'worker_id'            => $record->worker_id,
                    'task'                 => $record->task,
                    'style_sku_id'         => $record->style_sku_id,
                    'work_date'            => $record->work_date?->toDateString(),
                ]);
            } else {
                // updateQuietly bypasses model events to prevent observer re-entry
                $record->updateQuietly([
                    'rate_card_entry_id' => $result['rate_card_entry_id'],
                    'rate_amount'        => $result['rate_amount'],
                    'gross_earnings'     => $record->pairs_produced * $result['rate_amount'],
                ]);
            }
        }

        // ── 2. Shift adjustment detection ─────────────────────────────────
        // Runs in a try/catch so a detection failure never rolls back the
        // production record itself.
        try {
            $this->shiftAdjustmentService->detectAndRecord($record);
        } catch (\Throwable $e) {
            Log::warning('ShiftAdjustmentService: detection failed', [
                'production_record_id' => $record->id,
                'error'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fires after a ProductionRecord is updated.
     * Recomputes gross_earnings if pairs or rate changed.
     */
    public function updated(ProductionRecord $record): void
    {
        if ($record->wasChanged('pairs_produced') || $record->wasChanged('rate_amount')) {
            $record->updateQuietly([
                'gross_earnings' => $record->pairs_produced * $record->rate_amount,
            ]);
        }
    }
}
