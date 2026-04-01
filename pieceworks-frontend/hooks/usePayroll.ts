import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, type ApiEnvelope } from '@/lib/api-client';

// ── Domain types ─────────────────────────────────────────────────────────────

export type PayrollRunStatus = 'open' | 'processing' | 'locked' | 'paid';
export type PaymentStatus = 'pending' | 'processing' | 'paid' | 'failed';
export type ExceptionType =
  | 'min_wage_shortfall'
  | 'missing_rate'
  | 'negative_net_carry'
  | 'disputed_records'
  | 'manual';

export interface WeeklyPayrollRun {
  id: number;
  week_ref: string;            // 'YYYY-W##'
  start_date: string;
  end_date: string;
  status: PayrollRunStatus;
  total_gross: string;
  total_topups: string;
  total_deductions: string;
  total_net: string;
  locked_at: string | null;
  locked_by: number | null;
  released_at: string | null;
  released_by: number | null;
  created_at: string;
  updated_at: string;
  // Eager-loaded counts (from show endpoint)
  worker_payrolls_count?: number;
  exceptions_count?: number;
  unresolved_exceptions_count?: number;
}

export interface PayrollException {
  id: number;
  payroll_run_id: number;
  worker_id: number;
  worker_weekly_payroll_id: number | null;
  exception_type: ExceptionType;
  description: string;
  amount: string | null;
  resolved_at: string | null;
  resolved_by: number | null;
  resolution_note: string | null;
  created_at: string;
  updated_at: string;
  // Relations
  worker?: { id: number; name: string; grade: string };
  resolver?: { id: number; name: string } | null;
}

export interface CurrentPayrollResponse {
  week_ref: string;
  start_date: string;
  end_date: string;
  run: WeeklyPayrollRun | null;
  stats: {
    worker_count: number;
    exception_count: number;
    unresolved_exception_count: number;
    total_gross: number;
    total_net: number;
  } | null;
}

// ── Query keys ────────────────────────────────────────────────────────────────

export const payrollKeys = {
  all: ['payroll'] as const,
  current: () => [...payrollKeys.all, 'current'] as const,
  run: (weekRef: string) => [...payrollKeys.all, weekRef] as const,
  workers: (weekRef: string) => [...payrollKeys.all, weekRef, 'workers'] as const,
  exceptions: (weekRef: string) => [...payrollKeys.all, weekRef, 'exceptions'] as const,
};

// ── Query hooks ───────────────────────────────────────────────────────────────

/**
 * Current ISO week run + stats. Returns null run when not yet calculated.
 *
 * @example
 * const { data } = useCurrentPayroll();
 * // data?.data.run?.status
 * // data?.data.stats?.unresolved_exception_count
 */
export function useCurrentPayroll(options?: { refetchInterval?: number }) {
  return useQuery({
    queryKey: payrollKeys.current(),
    queryFn: () =>
      apiClient.get<ApiEnvelope<CurrentPayrollResponse>>('/payroll/current'),
    refetchInterval: options?.refetchInterval,
  });
}

// ── Mutation helpers ──────────────────────────────────────────────────────────

/** Invalidate all queries for a specific week + the current-week shortcut. */
function usePayrollInvalidator() {
  const queryClient = useQueryClient();
  return (weekRef: string) => {
    queryClient.invalidateQueries({ queryKey: payrollKeys.run(weekRef) });
    queryClient.invalidateQueries({ queryKey: payrollKeys.current() });
  };
}

// ── Mutation hooks ────────────────────────────────────────────────────────────

/**
 * Lock a payroll run.
 * Fails server-side if there are unresolved exceptions.
 *
 * @example
 * const lock = useLockPayroll();
 * lock.mutate({ weekRef: '2025-W12' });
 */
export function useLockPayroll() {
  const invalidate = usePayrollInvalidator();

  return useMutation({
    mutationFn: ({ weekRef }: { weekRef: string }) =>
      apiClient.post<ApiEnvelope<WeeklyPayrollRun>>(
        `/payroll/${weekRef}/lock`
      ),
    onSuccess: (_, { weekRef }) => invalidate(weekRef),
  });
}

/**
 * Release a locked payroll run for payment disbursement.
 * Transitions run to 'paid' and all worker lines to 'processing'.
 *
 * @example
 * const release = useReleasePayment();
 * release.mutate({ weekRef: '2025-W12' });
 */
export function useReleasePayment() {
  const invalidate = usePayrollInvalidator();

  return useMutation({
    mutationFn: ({ weekRef }: { weekRef: string }) =>
      apiClient.post<ApiEnvelope<WeeklyPayrollRun>>(
        `/payroll/${weekRef}/release`
      ),
    onSuccess: (_, { weekRef }) => invalidate(weekRef),
  });
}

/**
 * Resolve a single payroll exception. Requires a resolution_note (min 10 chars).
 * After resolution the run's exception count is re-fetched.
 *
 * @example
 * const resolve = useResolveException();
 * resolve.mutate({
 *   id: 7,
 *   weekRef: '2025-W12',
 *   resolution_note: 'Reviewed — minimum wage supplement is correct.',
 * });
 */
export function useResolveException() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      id,
      resolution_note,
    }: {
      id: number;
      weekRef: string;
      resolution_note: string;
    }) =>
      apiClient.patch<ApiEnvelope<PayrollException>>(
        `/payroll/exceptions/${id}/resolve`,
        { resolution_note }
      ),
    onSuccess: (_, { weekRef }) => {
      queryClient.invalidateQueries({ queryKey: payrollKeys.exceptions(weekRef) });
      queryClient.invalidateQueries({ queryKey: payrollKeys.run(weekRef) });
      queryClient.invalidateQueries({ queryKey: payrollKeys.current() });
    },
  });
}
