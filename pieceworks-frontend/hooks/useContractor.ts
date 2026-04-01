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

export const contractorKeys = {
  all:        ['contractors'] as const,
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
