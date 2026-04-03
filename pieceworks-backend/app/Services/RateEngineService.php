<?php

namespace App\Services;

use App\Models\GradeWageRate;
use App\Models\ProductionUnit;
use App\Models\RateCard;
use App\Models\RateCardEntry;
use App\Models\StyleSku;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class RateEngineService
{
    private const CACHE_BUST_KEY    = 'rate_card_bust';
    private const RATE_CARD_TTL     = 300;   // 5 minutes
    private const SKU_TIER_TTL      = 600;   // 10 minutes
    private const GRADE_WAGE_TTL    = 600;   // 10 minutes — grade wages rarely change mid-day

    /**
     * Per-request memoization of Worker and ProductionUnit lookups.
     *
     * Batch submissions send up to 100 rows that frequently reference the
     * same worker IDs and production unit.  Without memoisation each row
     * fires a separate SELECT, resulting in O(n) unnecessary round-trips.
     * Storing loaded instances in these maps reduces repeated lookups to O(1)
     * for the lifetime of the service object (one HTTP request).
     *
     * @var array<int, Worker>
     */
    private array $workerCache = [];

    /** @var array<int, ProductionUnit> */
    private array $unitCache = [];

    // ── Three-model earnings dispatcher (CR-001) ─────────────────────────────

    /**
     * Calculate gross earnings for a single production record.
     *
     * Dispatches to the correct wage model based on the ProductionUnit's
     * wage_model field: daily_grade | per_pair | hybrid.
     *
     * @return array{
     *   earnings: float,
     *   pairs: int,
     *   wage_model: string,
     *   rate_detail: string,
     *   rate_card_version: string|null,
     * }
     * @throws \RuntimeException when required reference data is missing.
     */
    public function calculateEarnings(
        int    $workerId,
        int    $productionUnitId,
        string $workDate,
        int    $pairsProduced,
        string $task,
        ?int   $styleSkuId = null    // nullable: daily_grade and hybrid models do not require a SKU
    ): array {
        $worker = $this->getWorker($workerId);
        $unit   = $this->getProductionUnit($productionUnitId);
        $date   = Carbon::parse($workDate)->startOfDay();

        $result = match ($unit->wage_model) {
            ProductionUnit::WAGE_MODEL_DAILY_GRADE => $this->calcDailyGrade($worker, $unit, $date),
            ProductionUnit::WAGE_MODEL_PER_PAIR    => $this->calcPerPair($worker, $unit, $date, $pairsProduced, $task, $styleSkuId),
            ProductionUnit::WAGE_MODEL_HYBRID      => $this->calcHybrid($worker, $unit, $date, $pairsProduced),
            default                                => throw new \RuntimeException("Unknown wage model: {$unit->wage_model}"),
        };

        return array_merge($result, [
            'pairs'      => $pairsProduced,
            'wage_model' => $unit->wage_model,
        ]);
    }

    // ── Private wage model calculators ───────────────────────────────────────

    /**
     * Daily-grade model: flat daily wage determined by worker grade.
     *
     * Looks up GradeWageRate on the active rate card for the work date.
     * earnings = daily_wage_pkr (pairs produced do not affect the amount).
     */
    private function calcDailyGrade(Worker $worker, ProductionUnit $unit, Carbon $date): array
    {
        $rateCard  = $this->getActiveRateCard($date->toDateString());
        $gradeRate = $this->resolveGradeWageRate($rateCard->id, $worker->grade);
        $earnings  = (float) $gradeRate->daily_wage_pkr;

        return [
            'earnings'          => $earnings,
            'rate_detail'       => "Grade {$worker->grade} daily wage",
            'rate_card_version' => $rateCard->version,
        ];
    }

    /**
     * Per-pair model: earnings = pairs_produced × rate_per_pair.
     *
     * Rate resolved from RateCardEntry by task + complexity_tier (from StyleSku)
     * + worker_grade, with an optional training-period discount.
     * Falls back to 'standard' tier when no entry exists for the SKU's tier.
     */
    private function calcPerPair(
        Worker         $worker,
        ProductionUnit $unit,
        Carbon         $date,
        int            $pairs,
        string         $task,
        ?int           $styleSkuId    // nullable — resolveComplexityTier falls back to 'standard'
    ): array {
        $rateCard       = $this->getActiveRateCard($date->toDateString());
        $complexityTier = $this->resolveComplexityTier($styleSkuId);

        // Primary lookup
        $entry = RateCardEntry::where('rate_card_id', $rateCard->id)
            ->where('task', $task)
            ->where('complexity_tier', $complexityTier)
            ->where('worker_grade', $worker->grade)
            ->first();

        // Fallback to standard tier
        if (! $entry && $complexityTier !== 'standard') {
            $entry = RateCardEntry::where('rate_card_id', $rateCard->id)
                ->where('task', $task)
                ->where('complexity_tier', 'standard')
                ->where('worker_grade', $worker->grade)
                ->first();
            if ($entry) {
                $complexityTier = 'standard';
            }
        }

        if (! $entry) {
            throw new \RuntimeException(
                "No rate entry found for task '{$task}' / tier '{$complexityTier}' / grade '{$worker->grade}' on rate card {$rateCard->version}."
            );
        }

        $ratePerPair             = (float) $entry->rate_pkr;
        $trainingDiscountApplied = false;

        // Training-period discount
        if ($worker->training_end_date !== null) {
            $trainingEnd = $worker->training_end_date instanceof Carbon
                ? $worker->training_end_date
                : Carbon::parse($worker->training_end_date);

            if ($trainingEnd->gte($date)) {
                $trainingPct = (float) ($rateCard->training_rate_pct ?? 100.0);
                if ($trainingPct < 100.0) {
                    $ratePerPair             = round($ratePerPair * $trainingPct / 100.0, 2);
                    $trainingDiscountApplied = true;
                }
            }
        }

        $earnings = round($pairs * $ratePerPair, 2);

        $tierLabel  = $complexityTier;
        $gradeLabel = $worker->grade;
        $suffix     = $trainingDiscountApplied ? ' (training rate)' : '';
        $rateDetail = "{$pairs} pairs x PKR {$ratePerPair} ({$task} / {$tierLabel} / {$gradeLabel}){$suffix}";

        return [
            'earnings'          => $earnings,
            'rate_detail'       => $rateDetail,
            'rate_card_version' => $rateCard->version,
        ];
    }

    /**
     * Hybrid model: daily floor (grade wage) + bonus per pair above standard output.
     *
     * earnings = daily_wage_pkr + max(0, pairs - standard_output_day) × bonus_rate_per_pair
     */
    private function calcHybrid(
        Worker         $worker,
        ProductionUnit $unit,
        Carbon         $date,
        int            $pairs
    ): array {
        $rateCard  = $this->getActiveRateCard($date->toDateString());
        $gradeRate = $this->resolveGradeWageRate($rateCard->id, $worker->grade);
        $floorWage = (float) $gradeRate->daily_wage_pkr;
        $standardOutput  = (int)   ($unit->standard_output_day ?? 0);
        $bonusRatePerPair = (float) ($unit->bonus_rate_per_pair ?? 0.0);

        $bonusPairs = max(0, $pairs - $standardOutput);
        $bonusAmount = round($bonusPairs * $bonusRatePerPair, 2);
        $earnings    = round($floorWage + $bonusAmount, 2);

        if ($bonusPairs > 0) {
            $rateDetail = "Floor PKR {$floorWage} ({$worker->grade}) + {$bonusPairs} bonus pairs x PKR {$bonusRatePerPair} = PKR {$earnings}";
        } else {
            $rateDetail = "Floor PKR {$floorWage} ({$worker->grade}), no bonus (below standard output of {$standardOutput})";
        }

        return [
            'earnings'          => $earnings,
            'rate_detail'       => $rateDetail,
            'rate_card_version' => $rateCard->version,
        ];
    }

    /**
     * Return the active rate card whose effective_date ≤ $workDate.
     * Latest effective date wins; uses same cache layer as resolveRateCard().
     *
     * @throws \RuntimeException when no active rate card covers the date.
     */
    private function getActiveRateCard(string $workDate): RateCard
    {
        $card = $this->resolveRateCard(Carbon::parse($workDate));

        if (! $card) {
            throw new \RuntimeException("No active rate card found for date {$workDate}.");
        }

        return $card;
    }

    // ── Legacy per-pair rate resolver (used by ProductionRecordObserver) ─────

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
        int            $workerId,
        string         $task,
        ?int           $styleSkuId,
        Carbon|string  $workDate
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

    // ── Cache management ─────────────────────────────────────────────────────

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

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Memoized Worker lookup — prevents repeated DB hits for the same ID
     * within a single batch submission.
     */
    private function getWorker(int $id): Worker
    {
        return $this->workerCache[$id] ??= Worker::findOrFail($id);
    }

    /**
     * Memoized ProductionUnit lookup — the same unit is referenced by every
     * row in a typical production session batch.
     */
    private function getProductionUnit(int $id): ProductionUnit
    {
        return $this->unitCache[$id] ??= ProductionUnit::findOrFail($id);
    }

    /**
     * Resolve (and cache) the GradeWageRate for a rate card + grade combination.
     *
     * Grade wages change infrequently (typically only when a new rate card
     * version is activated), so a 10-minute cache dramatically reduces
     * database round-trips during high-volume batch submissions.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    private function resolveGradeWageRate(int $rateCardId, string $grade): GradeWageRate
    {
        $bust     = Cache::get(self::CACHE_BUST_KEY, 0);
        $cacheKey = "grade_wage_{$bust}_{$rateCardId}_{$grade}";

        $raw = Cache::remember($cacheKey, self::GRADE_WAGE_TTL, function () use ($rateCardId, $grade) {
            return GradeWageRate::where('rate_card_id', $rateCardId)
                ->where('grade', $grade)
                ->firstOrFail()
                ->toArray();
        });

        // Reconstruct an unsaved model from the cached array so callers
        // can use attribute accessors (e.g. ->daily_wage_pkr).
        return (new GradeWageRate())->forceFill($raw);
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
