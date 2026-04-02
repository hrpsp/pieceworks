import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, type ApiEnvelope, type PaginatedEnvelope } from '@/lib/api-client';

// ── Types ─────────────────────────────────────────────────────────────────────

export interface ContractorWorkerBreakdown {
  contractor_id:   number;
  contractor_name: string;
  active_workers:  number;
  inactive_workers: number;
  total_workers:   number;
  pending_exceptions: number;
}

export interface ContractorDashboard {
  total_contractors:   number;
  active_contractors:  number;
  total_workers:       number;
  total_settlement_pkr: number;
  breakdown:           ContractorWorkerBreakdown[];
}

export interface ContractorWorker {
  id:          number;
  name:        string;
  cnic:        string;
  status:      'active' | 'inactive' | 'terminated';
  grade:       string;
  worker_type: string;
  join_date:   string;
}

export interface ContractorSettlementLine {
  contractor_id:       number;
  contractor_name:     string;
  worker_count:        number;
  gross_earnings:      number;
  deductions:          number;
  net_settlement:      number;
  advance_recoveries:  number;
  min_wage_supplements: number;
}

export interface ContractorSettlement {
  week_ref:      string;
  total_net:     number;
  total_gross:   number;
  lines:         ContractorSettlementLine[];
}

// ── Query keys ────────────────────────────────────────────────────────────────

// ── Contractor admin types ────────────────────────────────────────────────────

export interface Contractor {
  id:                  number;
  name:                string;
  ntn_cnic:            string | null;
  contact_person:      string | null;
  phone:               string | null;
  whatsapp:            string | null;
  contract_start_date: string | null;
  contract_end_date:   string | null;
  payment_cycle:       'weekly' | 'biweekly' | 'monthly';
  bank_account:        string | null;
  bank_name:           string | null;
  portal_access:       boolean;
  status:              'active' | 'suspended' | 'expired';
  created_at:          string;
  updated_at:          string;
}

export interface ContractorPayload {
  name:                string;
  ntn_cnic?:           string;
  contact_person?:     string;
  phone?:              string;
  whatsapp?:           string;
  contract_start_date?: string;
  contract_end_date?:  string;
  payment_cycle?:      'weekly' | 'biweekly' | 'monthly';
  bank_account?:       string;
  bank_name?:          string;
  portal_access?:      boolean;
  status?:             'active' | 'suspended' | 'expired';
}

// ── Query keys ────────────────────────────────────────────────────────────────

export const contractorKeys = {
  all:        ['contractors'] as const,
  lists:      () => [...contractorKeys.all, 'list'] as const,
  list:       (status?: string) => [...contractorKeys.lists(), status ?? 'all'] as const,
  detail:     (id: number) => [...contractorKeys.all, id] as const,
  dashboard:  () => [...contractorKeys.all, 'dashboard'] as const,
  workers:    (id?: number) => [...contractorKeys.all, id ?? 'all', 'workers'] as const,
  settlement: (weekRef: string) => [...contractorKeys.all, 'settlement', weekRef] as const,
};

// ── Hooks ─────────────────────────────────────────────────────────────────────

/**
 * Contractor dashboard — total counts + per-contractor worker breakdown.
 * Feeds summary cards and donut charts on the contractor page.
 */
export function useContractorDashboard() {
  return useQuery({
    queryKey: contractorKeys.dashboard(),
    queryFn:  () =>
      apiClient.get<ApiEnvelope<ContractorDashboard>>('/contractor/dashboard'),
  });
}

/**
 * Workers belonging to a specific contractor, or all contractor workers.
 */
export function useContractorWorkers(contractorId?: number) {
  return useQuery({
    queryKey: contractorKeys.workers(contractorId),
    queryFn:  () => {
      const path = contractorId
        ? `/contractor/workers?contractor_id=${contractorId}`
        : '/contractor/workers';
      return apiClient.get<PaginatedEnvelope<ContractorWorker>>(path);
    },
  });
}

/**
 * Settlement breakdown for a given ISO week.
 * Returns gross/net/deductions per contractor.
 */
export function useContractorSettlement(weekRef: string) {
  return useQuery({
    queryKey: contractorKeys.settlement(weekRef),
    queryFn:  () =>
      apiClient.get<ApiEnvelope<ContractorSettlement>>(
        `/contractor/settlement/${weekRef}`
      ),
    enabled: !!weekRef,
  });
}

// ── Admin contractor list & CRUD ──────────────────────────────────────────────

/**
 * Full paginated list of contractors for admin management.
 * Used by AddWorkerModal dropdown and Settings page contractor tab.
 */
export function useContractorsList(status?: 'active' | 'suspended' | 'expired') {
  return useQuery({
    queryKey: contractorKeys.list(status),
    queryFn:  () => {
      const params = new URLSearchParams({ per_page: '100' });
      if (status) params.set('status', status);
      return apiClient.get<PaginatedEnvelope<Contractor>>(`/contractors?${params}`);
    },
  });
}

/**
 * Single contractor by ID (admin view).
 */
export function useContractor(id: number | null | undefined) {
  return useQuery({
    queryKey: contractorKeys.detail(id!),
    queryFn:  () =>
      apiClient.get<ApiEnvelope<Contractor>>(`/contractors/${id}`),
    enabled: id != null && id > 0,
  });
}

/**
 * Create a new contractor (admin only).
 */
export function useCreateContractor() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: ContractorPayload) =>
      apiClient.post<ApiEnvelope<Contractor>>('/contractors', payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: contractorKeys.lists() });
    },
  });
}

/**
 * Update an existing contractor.
 */
export function useUpdateContractor(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: Partial<ContractorPayload>) =>
      apiClient.put<ApiEnvelope<Contractor>>(`/contractors/${id}`, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: contractorKeys.lists() });
      queryClient.invalidateQueries({ queryKey: contractorKeys.detail(id) });
    },
  });
}

/**
 * Soft-delete a contractor.
 */
export function useDeleteContractor() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) =>
      apiClient.delete<ApiEnvelope<null>>(`/contractors/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: contractorKeys.lists() });
    },
  });
}
