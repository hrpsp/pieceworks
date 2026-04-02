<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Line;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LineController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Line::with(['supervisor:id,name', 'factoryLocation:id,name,city']);

        if ($request->filled('factory_location_id')) {
            $query->where('factory_location_id', $request->factory_location_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $lines = $query->get();

        return $this->success($lines);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                    => 'required|string|max:100',
            'factory_location_id'     => 'required|exists:factory_locations,id',
            'default_shift'           => ['required', Rule::in(['morning', 'afternoon', 'night'])],
            'supervisor_id'           => 'nullable|exists:users,id',
            'default_contractor_id'   => 'nullable|exists:contractors,id',
            'capacity_pairs_per_day'  => 'nullable|integer|min:1',
            'status'                  => ['nullable', Rule::in(['active', 'maintenance', 'inactive'])],
        ]);

        $validated['status'] = $validated['status'] ?? 'active';
        $line = Line::create($validated);
        $line->load(['supervisor:id,name', 'factoryLocation:id,name,city']);

        return $this->created($line, 'Line created successfully');
    }

    public function show(int $id): JsonResponse
    {
        $line = Line::with(['supervisor:id,name', 'factoryLocation:id,name,city', 'defaultContractor:id,name'])
            ->findOrFail($id);

        return $this->success($line);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $line = Line::findOrFail($id);

        $validated = $request->validate([
            'name'                   => 'sometimes|string|max:100',
            'factory_location_id'    => 'sometimes|exists:factory_locations,id',
            'default_shift'          => ['sometimes', Rule::in(['morning', 'afternoon', 'night'])],
            'supervisor_id'          => 'nullable|exists:users,id',
            'default_contractor_id'  => 'nullable|exists:contractors,id',
            'capacity_pairs_per_day' => 'nullable|integer|min:1',
            'status'                 => ['sometimes', Rule::in(['active', 'maintenance', 'inactive'])],
        ]);

        $line->update($validated);
        $line->load(['supervisor:id,name', 'factoryLocation:id,name,city']);

        return $this->success($line, 'Line updated successfully');
    }
}
