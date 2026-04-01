'use client';

/**
 * Auth context — provides the authenticated user (with permissions) to the
 * entire client-side tree.
 *
 * On mount:
 *  1. Reads a cached user from localStorage (instant, avoids flash).
 *  2. If a token exists, calls /auth/me to get fresh user data.
 *  3. If the me endpoint doesn't include permissions, falls back to
 *     GET /users/{id}/permissions.
 *  4. Persists the enriched user back to localStorage.
 *
 * login():
 *  - POSTs /auth/login, stores token in localStorage + non-httpOnly cookie
 *    (cookie is read by middleware.ts for SSR route protection).
 *
 * logout():
 *  - Clears token from localStorage + cookie, hard-navigates to /login.
 */

import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from 'react';
import { apiClient, clearToken, getToken, setToken } from '@/lib/api-client';
import type { ApiResponse } from '@/types/api';

// ── Types ─────────────────────────────────────────────────────────────────────

export interface AuthUser {
  id:            number;
  name:          string;
  email:         string;
  role:          string;
  contractor_id: number | null;
  /** Permission slugs granted to this user via their assigned roles. */
  permissions:   string[];
}

interface LoginCredentials {
  email:    string;
  password: string;
}

interface LoginResponse {
  token: string;
  user:  AuthUser;
}

interface AuthContextValue {
  user:            AuthUser | null;
  token:           string | null;
  isLoading:       boolean;
  isAuthenticated: boolean;
  login:           (credentials: LoginCredentials) => Promise<void>;
  logout:          () => void;
  /** Re-fetch the current user from the server (e.g. after a role change). */
  refreshUser:     () => Promise<void>;
}

// ── Context ───────────────────────────────────────────────────────────────────

const AuthContext = createContext<AuthContextValue>({
  user:            null,
  token:           null,
  isLoading:       true,
  isAuthenticated: false,
  login:           async () => {},
  logout:          () => {},
  refreshUser:     async () => {},
});

const USER_KEY  = 'pw_user';
const TOKEN_KEY = 'pw_token';

// ── Cookie helpers ────────────────────────────────────────────────────────────

/** Write a non-httpOnly cookie readable by Next.js middleware. */
function setCookie(name: string, value: string, days = 7): void {
  const maxAge = days * 24 * 60 * 60;
  // SameSite=Lax; Secure omitted here to allow http in dev
  document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; SameSite=Lax`;
}

function deleteCookie(name: string): void {
  document.cookie = `${name}=; path=/; max-age=0`;
}

// ── Provider ──────────────────────────────────────────────────────────────────

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user,      setUser]      = useState<AuthUser | null>(null);
  const [token,     setTokenState] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  const logout = useCallback(() => {
    clearToken();
    deleteCookie(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    setUser(null);
    setTokenState(null);
    // Hard navigate so the Query cache is fully cleared too
    window.location.href = '/login';
  }, []);

  const refreshUser = useCallback(async () => {
    const storedToken = getToken();

    if (!storedToken) {
      setUser(null);
      setTokenState(null);
      setIsLoading(false);
      return;
    }

    setTokenState(storedToken);

    try {
      const res     = await apiClient.get<ApiResponse<AuthUser>>('/auth/me');
      const meUser  = res.data;

      // Fetch permissions separately if they weren't embedded in /auth/me
      let permissions: string[] = meUser.permissions ?? [];

      if (permissions.length === 0) {
        try {
          const pr = await apiClient.get<ApiResponse<{ permissions: string[] }>>(
            `/users/${meUser.id}/permissions`
          );
          permissions = pr.data.permissions ?? [];
        } catch {
          // Permissions endpoint unavailable — proceed with empty array
        }
      }

      const enriched: AuthUser = { ...meUser, permissions };
      setUser(enriched);
      localStorage.setItem(USER_KEY, JSON.stringify(enriched));
    } catch {
      // Token invalid or expired
      logout();
    } finally {
      setIsLoading(false);
    }
  }, [logout]);

  const login = useCallback(async ({ email, password }: LoginCredentials) => {
    const res = await apiClient.post<ApiResponse<LoginResponse>>('/auth/login', {
      email,
      password,
    });
    const { token: newToken, user: loggedInUser } = res.data;

    // Set token first so subsequent API calls include the Authorization header
    setToken(newToken);

    // Fetch permissions if not embedded
    let permissions: string[] = loggedInUser.permissions ?? [];
    if (permissions.length === 0) {
      try {
        const pr = await apiClient.get<ApiResponse<{ permissions: string[] }>>(
          `/users/${loggedInUser.id}/permissions`
        );
        permissions = pr.data.permissions ?? [];
      } catch {
        // proceed with empty
      }
    }

    const enriched: AuthUser = { ...loggedInUser, permissions };

    // Persist cookie + local cache
    setCookie(TOKEN_KEY, newToken);
    localStorage.setItem(USER_KEY, JSON.stringify(enriched));

    setTokenState(newToken);
    setUser(enriched);
  }, []);

  useEffect(() => {
    // Optimistic: render the cached user immediately to avoid layout flash
    const cachedUser  = localStorage.getItem(USER_KEY);
    const cachedToken = localStorage.getItem(TOKEN_KEY);

    if (cachedUser) {
      try { setUser(JSON.parse(cachedUser)); } catch { /* corrupt cache */ }
    }
    if (cachedToken) {
      setTokenState(cachedToken);
    }

    refreshUser();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        isLoading,
        isAuthenticated: !!user && !!token,
        login,
        logout,
        refreshUser,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

// ── Hook ──────────────────────────────────────────────────────────────────────

export function useAuth(): AuthContextValue {
  return useContext(AuthContext);
}
