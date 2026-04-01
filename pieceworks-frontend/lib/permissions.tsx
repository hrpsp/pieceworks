'use client';

/**
 * Permission utilities for the PieceWorks HRMS.
 *
 * PERMISSIONS  – typed constant of every permission slug in the system.
 * usePermission – hook that checks one permission against the auth context.
 * PermissionGate – component wrapper that renders children only when allowed.
 */

import React from 'react';
import { useAuth } from '@/contexts/auth-context';

// ── Permission slugs ──────────────────────────────────────────────────────────

/**
 * All permission slugs defined in the PieceWorks RBAC system.
 *
 * Usage:
 *   usePermission(PERMISSIONS.PAYROLL_LOCK)
 *   <PermissionGate permission={PERMISSIONS.WORKERS_CREATE}>
 */
export const PERMISSIONS = {
  // Workers
  WORKERS_VIEW_ALL:           'workers.view_all',
  WORKERS_CREATE:             'workers.create',
  WORKERS_EDIT:               'workers.edit',

  // Production
  PRODUCTION_ENTER:           'production.enter',
  PRODUCTION_EDIT_SAME_DAY:   'production.edit_same_day',
  PRODUCTION_BACKFILL:        'production.backfill',

  // Payroll
  PAYROLL_RUN:                'payroll.run',
  PAYROLL_LOCK:               'payroll.lock',
  PAYROLL_RELEASE:            'payroll.release',
  PAYROLL_REVERSE:            'payroll.reverse',

  // Exceptions
  EXCEPTIONS_RESOLVE:         'exceptions.resolve',

  // Rate cards
  RATE_CARDS_MANAGE:          'rate_cards.manage',

  // Ghost worker
  GHOST_WORKER_OVERRIDE:      'ghost_worker.override',

  // Rejections
  REJECTION_ENTER:            'rejection.enter',
  REJECTION_DISPUTE:          'rejection.dispute',

  // Reports
  REPORTS_VIEW_OWN:           'reports.view_own',
  REPORTS_VIEW_ALL:           'reports.view_all',
} as const;

/** Union type of every permission slug value. */
export type PermissionSlug = typeof PERMISSIONS[keyof typeof PERMISSIONS];

// ── Hook ──────────────────────────────────────────────────────────────────────

/**
 * Check whether the authenticated user holds a given permission.
 *
 * Returns `false` while the auth state is still loading so that UI
 * stays hidden until we know the user's actual rights.
 *
 * @example
 * const canLock = usePermission(PERMISSIONS.PAYROLL_LOCK);
 * // or with a raw slug:
 * const canLock = usePermission('payroll.lock');
 */
export function usePermission(permission: string): boolean {
  const { user, isLoading } = useAuth();
  if (isLoading || !user) return false;
  return user.permissions.includes(permission);
}

/**
 * Check whether the authenticated user holds ALL of the given permissions.
 *
 * @example
 * const canManage = usePermissions([PERMISSIONS.PAYROLL_LOCK, PERMISSIONS.PAYROLL_RELEASE]);
 */
export function usePermissions(permissions: string[]): boolean {
  const { user, isLoading } = useAuth();
  if (isLoading || !user) return false;
  return permissions.every(p => user.permissions.includes(p));
}

// ── PermissionGate ────────────────────────────────────────────────────────────

interface PermissionGateProps {
  /** The permission slug to check. */
  permission: string;
  /** Content to render when the user has the permission. */
  children: React.ReactNode;
  /**
   * Optional content to render when the user does NOT have the permission.
   * If omitted, nothing is rendered in the denied case.
   */
  fallback?: React.ReactNode;
}

/**
 * Renders `children` only when the authenticated user has the given permission.
 * Renders `fallback` (or nothing) otherwise.
 *
 * @example
 * <PermissionGate permission={PERMISSIONS.WORKERS_CREATE}>
 *   <CreateWorkerButton />
 * </PermissionGate>
 *
 * <PermissionGate
 *   permission={PERMISSIONS.PAYROLL_REVERSE}
 *   fallback={<p className="text-muted-foreground text-sm">Admin access required.</p>}
 * >
 *   <ReversePayrollButton />
 * </PermissionGate>
 */
export function PermissionGate({
  permission,
  children,
  fallback = null,
}: PermissionGateProps): React.ReactElement | null {
  const allowed = usePermission(permission);
  return allowed
    ? (React.isValidElement(children) ? children : <>{children}</>)
    : (fallback ? (React.isValidElement(fallback) ? fallback : <>{fallback}</>) : null);
}
