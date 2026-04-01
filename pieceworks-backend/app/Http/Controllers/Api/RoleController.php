<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function __construct(private PermissionService $permissionService) {}

    /**
     * GET /api/roles
     * List all roles with their assigned permissions.
     */
    public function index(): JsonResponse
    {
        $roles = DB::table('roles')->orderBy('name')->get();

        $permsByRole = DB::table('role_permissions')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->select('role_permissions.role_id', 'permissions.slug', 'permissions.name', 'permissions.module')
            ->get()
            ->groupBy('role_id');

        $data = $roles->map(function ($role) use ($permsByRole) {
            return [
                'id'          => $role->id,
                'name'        => $role->name,
                'slug'        => $role->slug,
                'description' => $role->description,
                'permissions' => ($permsByRole[$role->id] ?? collect())->map(fn($p) => [
                    'slug'   => $p->slug,
                    'name'   => $p->name,
                    'module' => $p->module,
                ])->values(),
            ];
        });

        return $this->success($data, 'Roles retrieved.');
    }

    /**
     * GET /api/users/{id}/permissions
     * Returns the resolved permission list for a specific user.
     */
    public function userPermissions(int $id): JsonResponse
    {
        $user = \App\Models\User::findOrFail($id);
        $permissions = $this->permissionService->getUserPermissions($user);

        return $this->success([
            'user_id'     => $user->id,
            'name'        => $user->name,
            'permissions' => $permissions,
        ], 'User permissions retrieved.');
    }

    /**
     * POST /api/users/{id}/roles
     * Assign a role to a user (idempotent). Flushes permission cache.
     *
     * Body: { "role_slug": "supervisor" }
     */
    public function assignRole(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'role_slug' => ['required', 'string', 'exists:roles,slug'],
        ]);

        $user = \App\Models\User::findOrFail($id);
        $role = DB::table('roles')->where('slug', $request->role_slug)->first();

        DB::table('user_roles')->insertOrIgnore([
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $this->permissionService->flushCache($user->id);

        $roles = DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->pluck('roles.slug');

        return $this->success([
            'user_id' => $user->id,
            'roles'   => $roles,
        ], "Role '{$request->role_slug}' assigned to user.");
    }

    /**
     * DELETE /api/users/{id}/roles/{roleSlug}
     * Remove a role from a user. Flushes permission cache.
     */
    public function revokeRole(int $id, string $roleSlug): JsonResponse
    {
        $user = \App\Models\User::findOrFail($id);
        $role = DB::table('roles')->where('slug', $roleSlug)->first();

        if (!$role) {
            return $this->error("Role '{$roleSlug}' not found.", 404);
        }

        DB::table('user_roles')
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->delete();

        $this->permissionService->flushCache($user->id);

        return $this->success(null, "Role '{$roleSlug}' revoked from user.");
    }
}
