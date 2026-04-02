<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RateCard;
use App\Models\RateCardEntry;
use App\Services\RateEngineService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RateCardController extends Controller
{
    // ── List ────────────────────────────────────────────────────────────────

    /**
     * GET /api/rate-cards
     *
     * All rate card versions with derived status, entry count, and approver.
     */
    public function index(): JsonResponse
    {
        $cards = RateCard::with('approver:id,name')
            ->withCount('entries')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (RateCard $c) => $this->cardSummary($c));

        return $this->success($cards, 'Rate cards retrieved.');
    }

    // ── Create ──────────────────────────────────────────────────────────────

    /**
     * POST /api/rate-cards
     *
     * Create a new rate card version with its entries.
     *
     * - effective_date must be today or future.
     * - If effective_date is today → auto-activate immediately, deactivate previous.
     * - If effective_date is future → card is 'scheduled'; previous card stays active.
     * - training_rate_pct (1–100) applied as multiplier when worker is in training period.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'effective_date'              => ['required', 'date', 'after_or_equal:today'],
            'notes'                       => ['nullable', 'string', 'max:1000'],
            'training_rate_pct'           => ['nullable', 'numeric', 'min:1', 'max:100'],
            'entries'                     => ['required', 'array', 'min:1'],
            'entries.*.task'              => ['required', 'string', 'max:100'],
            'entries.*.complexity_tier'   => ['required', 'in:standard,medium,complex'],
            'entries.*.worker_grade'      => ['required', 'in:A,B,C,D,trainee'],
            'entries.*.rate_pkr'          => ['required', 'numeric', 'min:0.01'],
        ]);

        // Check for duplicate task+tier+grade combos within the submitted entries
        $combos = collect($data['entries'])
            ->map(fn ($e) => "{$e['task']}|{$e['complexity_tier']}|{$e['worker_grade']}")
            ->duplicates();

        if ($combos->isNotEmpty()) {
            return $this->error(
                'Duplicate entries detected: ' . $combos->unique()->values()->implode(', '),
                422
            );
        }

        return DB::transaction(function () use ($data, $request) {
            $effectiveDate = Carbon::parse($data['effective_date']);
            $isActive      = $effectiveDate->lte(Carbon::today());

            if ($isActive) {
                RateCard::where('is_active', true)->update(['is_active' => false]);
            }

            $card = RateCard::create([
                'version'           => $this->generateVersion(),
                'effective_date'    => $data['effective_date'],
                'notes'             => $data['notes'] ?? null,
                'training_rate_pct' => $data['training_rate_pct'] ?? 100.00,
                'approved_by'       => $request->user()->id,
                'is_active'         => $isActive,
            ]);

            $now  = now();
            $rows = collect($data['entries'])->map(fn ($e) => [
                'rate_card_id'    => $card->id,
                'task'            => $e['task'],
                'complexity_tier' => $e['complexity_tier'],
                'worker_grade'    => $e['worker_grade'],
                'rate_pkr'        => $e['rate_pkr'],
                'created_at'      => $now,
                'updated_at'      => $now,
            ])->all();

            RateCardEntry::insert($rows);

            if ($isActive) {
                RateEngineService::flushCache();
            }

            $message = $isActive
                ? "Rate card {$card->version} created and activated immediately."
                : "Rate card {$card->version} created. Will take effect on {$data['effective_date']}.";

            return $this->created(
                $card->fresh(['entries', 'approver:id,name']),
                $message
            );
        });
    }

    // ── Static named routes (MUST precede {id} wildcard) ────────────────────

    /**
     * GET /api/rate-cards/active
     *
     * The currently live rate card with all its entries.
     */
    public function active(): JsonResponse
    {
        $card = RateCard::with(['entries', 'approver:id,name'])
            ->where('is_active', true)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        if (! $card) {
            return $this->error('No active rate card found. Please create and activate one.', 404);
        }

        return $this->success(
            array_merge($card->toArray(), ['status' => $card->status]),
            'Active rate card retrieved.'
        );
    }

    /**
     * GET /api/rate-cards/history
     *
     * Full version history ordered newest first.
     */
    public function history(): JsonResponse
    {
        $history = RateCard::with('approver:id,name')
            ->withCount('entries')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (RateCard $c) => $this->cardSummary($c));

        return $this->success($history, 'Rate card history retrieved.');
    }

    // ── Show ────────────────────────────────────────────────────────────────

    /**
     * GET /api/rate-cards/{id}
     *
     * Full rate card with all entries, grouped by task for readability.
     */
    public function show(int $id): JsonResponse
    {
        $card = RateCard::with(['entries', 'approver:id,name'])->findOrFail($id);

        $entriesByTask = $card->entries
            ->sortBy(['task', 'complexity_tier', 'worker_grade'])
            ->groupBy('task')
            ->map(fn ($group) => $group->values());

        return $this->success([
            'rate_card'      => array_merge($card->toArray(), ['status' => $card->status]),
            'entries_by_task'=> $entriesByTask,
        ], 'Rate card retrieved.');
    }

    // ── Entries ─────────────────────────────────────────────────────────────

    /**
     * GET /api/rate-cards/{id}/entries
     *
     * Flat list of all entries for a rate card — used by the frontend matrix view.
     */
    public function entries(int $id): JsonResponse
    {
        $card    = RateCard::findOrFail($id);
        $entries = $card->entries()
            ->orderBy('task')
            ->orderBy('worker_grade')
            ->orderBy('complexity_tier')
            ->get();

        return $this->success($entries, 'Rate card entries retrieved.');
    }

    // ── Activate ────────────────────────────────────────────────────────────

    /**
     * POST /api/rate-cards/{id}/activate
     *
     * Manually activate a rate card, deactivating any currently active one.
     * Flushes the rate engine cache so new records immediately pick up the new rates.
     */
    public function activate(Request $request, int $id): JsonResponse
    {
        $card = RateCard::with('entries')->findOrFail($id);

        if ($card->is_active) {
            return $this->error("Rate card {$card->version} is already active.", 409);
        }

        if ($card->entries()->count() === 0) {
            return $this->error('Cannot activate a rate card with no entries.', 422);
        }

        DB::transaction(function () use ($card, $request) {
            $previously = RateCard::where('is_active', true)
                ->orderByDesc('effective_date')
                ->first();

            RateCard::where('is_active', true)->update(['is_active' => false]);

            $card->update([
                'is_active'   => true,
                'approved_by' => $card->approved_by ?? $request->user()->id,
            ]);

            RateEngineService::flushCache();

            // Audit trail
            DB::table('audit_logs')->insert([
                'user_id'    => $request->user()->id,
                'action'     => 'activated',
                'model_type' => RateCard::class,
                'model_id'   => $card->id,
                'old_values' => json_encode([
                    'previously_active_version' => $previously?->version,
                    'previously_active_id'      => $previously?->id,
                ]),
                'new_values' => json_encode([
                    'version'        => $card->version,
                    'effective_date' => $card->effective_date->toDateString(),
                    'is_active'      => true,
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->success(
            $card->fresh(['approver:id,name', 'entries'])->toArray() + ['status' => 'active'],
            "Rate card {$card->version} activated. Rate engine cache flushed."
        );
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Auto-generate a version string: v{N} where N = total cards + 1.
     * Produces v1, v2, v3 … — simple and unambiguous.
     */
    private function generateVersion(): string
    {
        $next = RateCard::count() + 1;
        return "v{$next}";
    }

    private function cardSummary(RateCard $card): array
    {
        return [
            'id'                 => $card->id,
            'version'            => $card->version,
            'effective_date'     => $card->effective_date->toDateString(),
            'status'             => $card->status,
            'is_active'          => $card->is_active,
            'training_rate_pct'  => (float) $card->training_rate_pct,
            'notes'              => $card->notes,
            'entries_count'      => $card->entries_count ?? $card->entries()->count(),
            'approved_by'        => $card->approver?->name,
            'created_at'         => $card->created_at->toDateTimeString(),
        ];
    }
}
