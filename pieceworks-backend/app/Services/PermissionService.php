<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class PermissionService
{
    /**
     * Returns all permission slugs assigned to the user via their roles.
     * Result is cached per-user for 5 minutes.
     */
    public function getUserPermissions(User $user): array
    {
        return Cache::remember("user_permissions_{$user->id}", 300, function () use ($user) {
            return \DB::table('user_roles')
                ->join('role_permissions', 'user_roles.role_id', '=', 'role_permissions.role_id')
                ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->where('user_roles.user_id', $user->id)
                ->pluck('permissions.slug')
                ->unique()
                ->values()
                ->all();
        });
    }

    /**
     * Checks whether a user has a specific permission slug.
     */
    public function hasPermission(User $user, string $permission): bool
    {
        return in_array($permission, $this->getUserPermissions($user), true);
    }

    /**
     * Flush the cached permissions for a user (call after role changes).
     */
    public function flushCache(int $userId): void
    {
        Cache::forget("user_permissions_{$userId}");
    }
}
