'use client';

/**
 * Thin wrapper around AuthContext.
 *
 * Exposes: user, token, login, logout, isAuthenticated, hasPermission.
 * Use this hook in components instead of importing useAuth from the context
 * directly — it adds the hasPermission() convenience helper.
 */

import { useAuth as useAuthContext } from '@/contexts/auth-context';
import type { AuthUser } from '@/contexts/auth-context';

export type { AuthUser };

export interface UseAuthReturn {
  user:            AuthUser | null;
  token:           string | null;
  isLoading:       boolean;
  isAuthenticated: boolean;
  login:           (credentials: { email: string; password: string }) => Promise<void>;
  logout:          () => void;
  refreshUser:     () => Promise<void>;
  /** Returns true when the current user holds the given permission slug. */
  hasPermission:   (permission: string) => boolean;
}

export function useAuth(): UseAuthReturn {
  const ctx = useAuthContext();

  function hasPermission(permission: string): boolean {
    if (ctx.isLoading || !ctx.user) return false;
    return ctx.user.permissions.includes(permission);
  }

  return { ...ctx, hasPermission };
}
