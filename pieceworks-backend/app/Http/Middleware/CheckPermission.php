<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function __construct(private PermissionService $permissionService) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user || !$this->permissionService->hasPermission($user, $permission)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Forbidden: you do not have the required permission.',
                'data'    => null,
            ], 403);
        }

        return $next($request);
    }
}
