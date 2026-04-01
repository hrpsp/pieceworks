<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StyleSku;
use App\Services\RateEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StyleSkuController extends Controller
{
    // ── List ────────────────────────────────────────────────────────────────

    /**
     * GET /api/style-skus?complexity_tier=&search=
     *
     * All SKUs with their current complexity tier.
     * Filterable by tier; searchable by style_code or style_name.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'complexity_tier' => ['nullable', 'in:standard,medium,complex'],
            'search'          => ['nullable', 'string', 'max:100'],
        ]);

        $query = StyleSku::orderBy('style_code');

        if ($tier = $request->input('complexity_tier')) {
            $query->where('complexity_tier', $tier);
        }

        if ($search = $request->input('search')) {
            $query->where(fn ($q) =>
                $q->where('style_code', 'like', "%{$search}%")
                  ->orWhere('style_name', 'like', "%{$search}%")
            );
        }

        $skus = $query->get();

        return $this->success([
            'data'    => $skus,
            'counts'  => [
                'total'    => $skus->count(),
                'standard' => $skus->where('complexity_tier', 'standard')->count(),
                'medium'   => $skus->where('complexity_tier', 'medium')->count(),
                'complex'  => $skus->where('complexity_tier', 'complex')->count(),
            ],
        ], 'SKUs retrieved.');
    }

    // ── Create ──────────────────────────────────────────────────────────────

    /**
     * POST /api/style-skus
     *
     * Create a new style/SKU record.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'style_code'      => ['required', 'string', 'max:50', 'unique:style_sku,style_code'],
            'style_name'      => ['required', 'string', 'max:255'],
            'complexity_tier' => ['required', 'in:standard,medium,complex'],
        ]);

        $sku = StyleSku::create($data);

        return $this->created($sku, "SKU {$sku->style_code} created.");
    }

    // ── Update Tier ─────────────────────────────────────────────────────────

    /**
     * PATCH /api/style-skus/{id}/tier
     *
     * Change the complexity tier of a SKU.
     *
     * Side-effects:
     *   - Flushes the cached tier for this SKU so the rate engine picks up
     *     the new tier immediately on the next production record.
     *   - Writes to audit_logs.
     *
     * Note: already-calculated production records keep their stored rate_amount
     * (mid-week revision rule). Only new records created after this change will
     * use the updated tier.
     */
    public function updateTier(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'complexity_tier' => ['required', 'in:standard,medium,complex'],
        ]);

        $sku    = StyleSku::findOrFail($id);
        $oldTier = $sku->complexity_tier;

        if ($oldTier === $data['complexity_tier']) {
            return $this->error(
                "SKU {$sku->style_code} is already on tier '{$oldTier}'.",
                409
            );
        }

        DB::transaction(function () use ($sku, $data, $oldTier, $request) {
            $sku->update(['complexity_tier' => $data['complexity_tier']]);

            // Flush cached tier so rate engine sees the change immediately
            RateEngineService::flushSkuCache($sku->id);

            // Audit log — same format as AuditObserver
            DB::table('audit_logs')->insert([
                'user_id'    => $request->user()->id,
                'action'     => 'updated',
                'model_type' => StyleSku::class,
                'model_id'   => $sku->id,
                'old_values' => json_encode(['complexity_tier' => $oldTier]),
                'new_values' => json_encode(['complexity_tier' => $data['complexity_tier']]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->success(
            $sku->fresh(),
            "SKU {$sku->style_code} tier changed from '{$oldTier}' to '{$data['complexity_tier']}'. "
            . "New records will use the updated tier; existing production records are unaffected."
        );
    }
}
