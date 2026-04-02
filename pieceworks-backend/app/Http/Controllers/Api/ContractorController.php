<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\ContractorSettlement;
use App\Models\ContractorPerformanceScore;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContractorController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Contractor::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $contractors = $query->withCount('workers')->paginate(20);

        return $this->success([
            'data' => $contractors->items(),
            'meta' => [
                'current_page' => $contractors->currentPage(),
                'last_page'    => $contractors->lastPage(),
                'per_page'     => $contractors->perPage(),
                'total'        => $contractors->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:255',
            'ntn_cnic'            => 'nullable|string|max:20',
            'contact_person'      => 'nullable|string|max:255',
            'phone'               => 'nullable|string|max:20',
            'whatsapp'            => 'nullable|string|max:20',
            'contract_start_date' => 'nullable|date',
            'contract_end_date'   => 'nullable|date|after_or_equal:contract_start_date',
            'payment_cycle'       => ['nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'bank_account'        => 'nullable|string|max:30',
            'bank_name'           => 'nullable|string|max:100',
            'portal_access'       => 'boolean',
            'status'              => ['nullable', Rule::in(['active', 'suspended', 'expired'])],
        ]);

        $validated['status'] = $validated['status'] ?? 'active';
        $contractor = Contractor::create($validated);

        return $this->created($contractor, 'Contractor created successfully');
    }

    public function show(int $id): JsonResponse
    {
        $contractor = Contractor::with(['workers:id,name,status,grade,worker_type'])->findOrFail($id);

        return $this->success($contractor);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $validated = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'ntn_cnic'            => 'nullable|string|max:20',
            'contact_person'      => 'nullable|string|max:255',
            'phone'               => 'nullable|string|max:20',
            'whatsapp'            => 'nullable|string|max:20',
            'contract_start_date' => 'nullable|date',
            'contract_end_date'   => 'nullable|date',
            'payment_cycle'       => ['nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'bank_account'        => 'nullable|string|max:30',
            'bank_name'           => 'nullable|string|max:100',
            'portal_access'       => 'boolean',
            'status'              => ['nullable', Rule::in(['active', 'suspended', 'expired'])],
        ]);

        $contractor->update($validated);

        return $this->success($contractor, 'Contractor updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);
        $contractor->update(['status' => 'expired']);

        return $this->success(null, 'Contractor deactivated');
    }

    public function workers(int $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $workers = $contractor->workers()
            ->with(['compliance:worker_id,eobi_number,pessi_number'])
            ->paginate(20);

        return $this->success([
            'data' => $workers->items(),
            'meta' => [
                'current_page' => $workers->currentPage(),
                'last_page'    => $workers->lastPage(),
                'per_page'     => $workers->perPage(),
                'total'        => $workers->total(),
            ],
        ]);
    }

    public function settlements(Request $request, int $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $query = ContractorSettlement::where('contractor_id', $id)
            ->orderBy('week_ref', 'desc');

        if ($request->filled('week_ref')) {
            $query->where('week_ref', $request->week_ref);
        }

        $settlements = $query->paginate(10);

        return $this->success([
            'data' => $settlements->items(),
            'meta' => [
                'current_page' => $settlements->currentPage(),
                'last_page'    => $settlements->lastPage(),
                'per_page'     => $settlements->perPage(),
                'total'        => $settlements->total(),
            ],
        ]);
    }

    public function performanceScores(int $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);

        $scores = ContractorPerformanceScore::where('contractor_id', $id)
            ->orderBy('week_ref', 'desc')
            ->take(12)
            ->get();

        return $this->success($scores);
    }
}
