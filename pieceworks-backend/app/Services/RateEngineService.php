<?php

namespace App\Services;

use App\Models\RateCard;
use App\Models\RateCardEntry;
use App\Models\StyleSku;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class RateEngineService
{
    private const CACHE_BUST_KEY    = 'rate_card_bust';
    private const RATE_CARD_TTL     = 300;  // 5 minutes
    private const SKU_TIER_TTL      = 600;  // 10 minutes

    /**
     * Resolve the PKR rate for a given worker performing a task on a date.
     *
     * Resolution order:
     *   1. Find the active rate card whose effective_date ≤ work_date (latest wins).
     *   2. Determine complexity_tier from style_sku (falls back to 'standard').
     *   3. Look up rate_card_entries by task + complexity_tier + worker_grade.
     *   4. If worker is still in their training period, apply training_rate_pct
     *      from the rate card (e.g. 80 = 80% of the base rate).
     *   5. Return rate, entry ID, and rate card metadata.
     *
     * Mid-week rate revision rule:
     *   Records already persisted keep their stored rate_amount.
     *   This method is only called for new records (rate_card_entry_id IS NULL in observer).
     *   It returns the rate from the card whose effective_date ≤ work_date, so records
     *   created before a new card's effective_date automatically use the correct version.
     *   Retroactive recalculation requires an explicit admin override with audit logging.
     *
     * Returns null when no rate card covers the date, or no matching entry exists.
     *
     * @return array{
     *   rate_amount: float,
     *   rate_card_entry_id: int,
     *   rate_card_id: int,
     *   rate_card_version: string,
     *   complexity_tier: string,
     *   worker_grade: string,
     *   training_discount_applied: bool,
     * }|null
     */
    public function calculateRate(
        int $workerId,
        string $task,
        ?int $styleSkuId,
        Carbon|string $workDate
    ): ?array {
        $workDate = Carbon::parse($workDate)->startOfDay();

        $worker = Worker::find($workerId);
        if (! $worker) {
            return null;
        }
        $workerGrade = $worker->grade;

        $complexityTier = $this->resolveComplexityTier($styleSkuId);

        $rateCard = $this->resolveRateCard($workDate);
        if (! $rateCard) {
            return null;
        }

        // Primary lookup: exact task + tier + grade match
        $entry = RateCardEntry::where('rate_card_id', $rateCard->id)
            ->where('task', $task)
            ->where('complexity_tier', $complexityTier)
            ->where('worker_grade', $workerGrade)
            ->first();

        // Fallback: same grade, standard tier (when no entry for this SKU's tier)
        if (! $entry && $complexityTier !== 'standard') {
            $entry = RateCardEntry::where('rate_card_id', $rateCard->id)
                ->where('task', $task)
                ->where('complexity_tier', 'standard')
                ->where('worker_grade', $workerGrade)
                ->first();
        }

        if (! $entry) {
            return null;
        }

        $rateAmount              = (float) $entry->rate_pkr;
        $trainingDiscountApplied = false;

        // Training period discount — apply if worker's training_end_date >= work_date
        if ($worker->training_end_date !== null) {
            $trainingEnd = $worker->training_end_date instanceof Carbon
                ? $worker->training_end_date
                : Carbon::parse($worker->training_end_date);

            if ($trainingEnd->gte($workDate)) {
                $trainingPct = (float) ($rateCard->training_rate_pct ?? 100.0);
                if ($trainingPct < 100.0) {
                    $rateAmount              = round($rateAmount * $trainingPct / 100.0, 2);
                    $trainingDiscountApplied = true;
                }
            }
        }

        return [
            'rate_amount'               => $rateAmount,
            'rate_card_entry_id'        => $entry->id,
            'rate_card_id'              => $rateCard->id,
            'rate_card_version'         => $rateCard->version,
            'complexity_tier'           => $complexityTier,
            'worker_grade'              => $workerGrade,
            'training_discount_applied' => $trainingDiscountApplied,
        ];
    }

    /**
     * Resolve the active rate card covering a given date.
     *
     * Cache key includes a bust counter so that activating a new card
     * immediately invalidates all cached lookups (cache-friendly across
     * file/array/Redis drivers without pattern deletes).
     */
    public function resolveRateCard(Carbon $workDate): ?RateCard
    {
        $bust     = Cache::get(self::CACHE_BUST_KEY, 0);
        $cacheKey = "rate_card_{$bust}_{$workDate->toDateString()}";

        return Cache::remember($cacheKey, self::RATE_CARD_TTL, function () use ($workDate) {
            return RateCard::where('is_active', true)
                ->where('effective_date', '<=', $workDate->toDateString())
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->first();
        });
    }

    /**
     * Invalidate all cached rate card lookups.
     * Call this whenever a rate card is activated or deactivated.
     */
    public static function flushCache(): void
    {
        Cache::increment(self::CACHE_BUST_KEY);
    }

    /**
     * Invalidate the cached complexity tier for a single SKU.
     * Call this when a SKU's complexity_tier is updated.
     */
    public static function flushSkuCache(int $styleSkuId): void
    {
        Cache::forget('sku_tier_' . $styleSkuId);
    }

    /**
     * Resolve complexity tier from a style SKU.
     * Returns 'standard' when no SKU is provided.
     */
    private function resolveComplexityTier(?int $styleSkuId): string
    {
        if (! $styleSkuId) {
            return 'standard';
        }

        return Cache::remember('sku_tier_' . $styleSkuId, self::SKU_TIER_TTL, function () use ($styleSkuId) {
            return StyleSku::find($styleSkuId)?->complexity_tier ?? 'standard';
        });
    }
}
