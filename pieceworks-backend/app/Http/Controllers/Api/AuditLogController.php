<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    /**
     * GET /api/audit-logs
     * Paginated audit trail. Admin only (enforced via route middleware).
     *
     * Query params:
     *   user_id      filter by acting user
     *   model_type   e.g. App\Models\Worker (short form 'Worker' also accepted)
     *   date_from    Y-m-d
     *   date_to      Y-m-d
     *   per_page     default 50
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'    => ['nullable', 'integer'],
            'model_type' => ['nullable', 'string'],
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = DB::table('audit_logs')
            ->leftJoin('users', 'audit_logs.user_id', '=', 'users.id')
            ->select(
                'audit_logs.*',
                'users.name as user_name',
                'users.email as user_email'
            )
            ->orderBy('audit_logs.created_at', 'desc');

        if ($userId = $request->integer('user_id')) {
            $query->where('audit_logs.user_id', $userId);
        }

        if ($modelType = $request->string('model_type')->toString()) {
            // Accept short class names like "Worker" or full FQCN
            if (!str_contains($modelType, '\\')) {
                $modelType = 'App\\Models\\' . $modelType;
            }
            $query->where('audit_logs.model_type', $modelType);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('audit_logs.created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('audit_logs.created_at', '<=', $dateTo);
        }

        $perPage = $request->integer('per_page', 50);
        $paginated = $query->paginate($perPage);

        // Decode JSON columns for readability
        $items = collect($paginated->items())->map(function ($row) {
            $row = (array) $row;
            $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
            $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;
            return $row;
        });

        return $this->success([
            'data' => $items,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
            'links' => [
                'next' => $paginated->nextPageUrl(),
                'prev' => $paginated->previousPageUrl(),
            ],
        ], 'Audit logs retrieved.');
    }
}
