import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import type { ApiEnvelope } from '@/lib/api-client';

// ── Types ─────────────────────────────────────────────────────────────────────

export type RiskLevel = 'medium' | 'high';

export interface GhostWorkerFlag {
  id:                   number;
  worker_id:            number;
  production_record_id: number | null;
  work_date:            string;
  risk_level:           RiskLevel;
  biometric_present:    boolean;
  production_anomaly:   boolean;
  pairs_produced:       string;
  four_week_avg:        string;
  std_dev:              string;
  override_reason:      string | null;
  overridden_at:        string | null;
  overridden_by:        number | null;
  resolved_at:          string | null;
  worker: {
    id:           number;
    name:         string;
    grade:        string;
    biometric_id: string | null;
  } | null;
  production_record: {
    id:                 number;
    pairs_produced:     string;
    shift:              string;
    validation_status:  string;
    ghost_risk_level:   string | null;
  } | null;
  overridden_by_user: { id: number; name: string } | null;
}

export interface GhostFlagsResponse {
  data: GhostWorkerFlag[];
  meta: {
    current_page: number;
    last_page:    number;
    per_page:     number;
    total:        number;
  };
}

export interface GhostFlagsParams {
  risk_level?: RiskLevel | '';
  resolved?:   boolean;
  per_page?:   number;
  page?:       number;
}

// ── Query keys ────────────────────────────────────────────────────────────────

const ghostKeys = {
  all:   ['ghost-worker'] as const,
  flags: (params: GhostFlagsParams) => [...ghostKeys.all, 'flags', params] as const,
};

// ── Hooks ─────────────────────────────────────────────────────────────────────

/**
 * GET /api/ghost-worker/flags
 * Supports filtering by risk_level and resolved status.
 */
export function useGhostWorkerFlags(params: GhostFlagsParams = {}) {
  // Build query-string manually (apiClient.get doesn't support params object)
  const qs = new URLSearchParams();
  if (params.risk_level)           qs.set('risk_level', params.risk_level);
  if (params.resolved !== undefined) qs.set('resolved', params.resolved ? '1' : '0');
  if (params.per_page)             qs.set('per_page',  String(params.per_page));
  if (params.page)                 qs.set('page',      String(params.page));
  const queryString = qs.toString();

  return useQuery({
    queryKey: ghostKeys.flags(params),
    queryFn:  () =>
      apiClient.get<ApiEnvelope<GhostFlagsResponse>>(
        `/ghost-worker/flags${queryString ? '?' + queryString : ''}`
      ),
    placeholderData: (prev) => prev,
  });
}

/**
 * POST /api/ghost-worker/{id}/override
 * Clears the ghost flag and restores the production record to pending.
 */
export function useOverrideGhostFlag() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      apiClient.post(`/ghost-worker/${id}/override`, { reason }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ghostKeys.all });
    },
  });
}
