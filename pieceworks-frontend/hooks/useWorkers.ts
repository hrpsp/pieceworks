import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, type ApiEnvelope, type PaginatedEnvelope } from '@/lib/api-client';

// ── Domain types ─────────────────────────────────────────────────────────────

export interface Worker {
  id: number;
  contractor_id: number | null;
  name: string;
  cnic: string;
  biometric_id: string | null;
  worker_type: 'direct' | 'contractor';
  grade: string;
  default_shift: 'morning' | 'evening' | 'night';
  default_line_id: number | null;
  training_period: number;
  training_end_date: string | null;
  payment_method: 'cash' | 'bank' | 'easypaisa' | 'jazzcash';
  payment_number: string | null;
  whatsapp: string | null;
  eobi_number: string | null;
  pessi_number: string | null;
  join_date: string;
  status: 'active' | 'inactive' | 'terminated';
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  // Computed
  is_in_training?: boolean;
}

export interface WorkerFilters {
  contractor_id?: number;
  status?: 'active' | 'inactive' | 'terminated';
  shift?: 'morning' | 'evening' | 'night';
  search?: string;
  page?: number;
  per_page?: number;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function buildQueryString(filters?: WorkerFilters): string {
  if (!filters) return '';
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== null && value !== '') {
      params.set(key, String(value));
    }
  }
  const qs = params.toString();
  return qs ? `?${qs}` : '';
}

// ── Query keys ────────────────────────────────────────────────────────────────

export const workerKeys = {
  all: ['workers'] as const,
  lists: () => [...workerKeys.all, 'list'] as const,
  list: (filters: WorkerFilters) => [...workerKeys.lists(), filters] as const,
  detail: (id: number) => [...workerKeys.all, id] as const,
};

// ── Hooks ─────────────────────────────────────────────────────────────────────

/**
 * Paginated worker list with optional filters.
 *
 * @example
 * const { data, isPending } = useWorkers({ status: 'active', search: 'Ahmed' });
 * // data?.data → Worker[]
 * // data?.meta.total → number
 */
export function useWorkers(filters?: WorkerFilters) {
  const safeFilters = filters ?? {};
  return useQuery({
    queryKey: workerKeys.list(safeFilters),
    queryFn: () =>
      apiClient.get<PaginatedEnvelope<Worker>>(
        `/workers${buildQueryString(safeFilters)}`
      ),
  });
}

/**
 * Single worker by ID.
 * Query is disabled until a valid id is provided.
 *
 * @example
 * const { data } = useWorker(42);
 * // data?.data → Worker
 */
export function useWorker(id: number | null | undefined) {
  return useQuery({
    queryKey: workerKeys.detail(id!),
    queryFn: () =>
      apiClient.get<ApiEnvelope<Worker>>(`/workers/${id}`),
    enabled: id != null && id > 0,
  });
}

// ── Create worker ─────────────────────────────────────────────────────────────

export interface CreateWorkerPayload {
  name:            string;
  cnic:            string;
  grade:           string;
  default_shift:   'morning' | 'evening' | 'night';
  join_date:       string;
  worker_type:     'direct' | 'contractor';
  contractor_id?:  number | null;
  payment_method:  'cash' | 'bank' | 'easypaisa' | 'jazzcash';
  payment_number?: string;
  whatsapp?:       string;
  status:          'active' | 'inactive';
}

export function useCreateWorker() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateWorkerPayload) =>
      apiClient.post<ApiEnvelope<Worker>>('/workers', payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: workerKeys.all });
    },
  });
}

// ── Worker sub-resource types ─────────────────────────────────────────────────

export interface ProductionRecord {
  id:                number;
  work_date:         string;
  shift:             string;
  task:              string;
  pairs_produced:    number;
  gross_earnings:    string;
  validation_status: string;
  line?:             { name: string };
}

export interface WorkerStatementLine {
  description: string;
  amount:      number;
  type:        'credit' | 'debit';
}

export interface WorkerStatement {
  worker_id:       number;
  worker_name:     string;
  week_ref:        string;
  gross_earnings:  number;
  deductions:      number;
  net_pay:         number;
  lines:           WorkerStatementLine[];
  generated_at:    string | null;
  whatsapp_sent_at: string | null;
}

export interface WorkerAdvance {
  id:              number;
  week_ref:        string;
  amount:          string;
  status:          string;
  carry_weeks:     number;
  amount_deducted: string;
}

export interface ShiftAdjustment {
  id:               number;
  work_date:        string;
  shift:            string;
  shift_adjustment: string;
  shift_adj_reason: string | null;
}

// ── Worker sub-resource hooks ─────────────────────────────────────────────────

export function useWorkerProduction(id: number, weekRef: string) {
  return useQuery({
    queryKey: ['workers', id, 'production', weekRef],
    queryFn:  () =>
      apiClient.get<{ data: ProductionRecord[]; meta: { total: number } }>(
        `/workers/${id}/production-history?week_ref=${weekRef}&per_page=50`
      ),
    enabled: id > 0 && !!weekRef,
  });
}

export function useWorkerStatement(id: number, weekRef: string) {
  return useQuery({
    queryKey: ['workers', id, 'statement', weekRef],
    queryFn:  () =>
      apiClient.get<ApiEnvelope<WorkerStatement>>(
        `/workers/${id}/statement/${weekRef}`
      ),
    enabled: id > 0 && !!weekRef,
  });
}

export function useWorkerAdvances(id: number) {
  return useQuery({
    queryKey: ['workers', id, 'advances'],
    queryFn:  () =>
      apiClient.get<{ data: WorkerAdvance[] }>(`/workers/${id}/advances`),
    enabled: id > 0,
  });
}

export function useWorkerShiftAdjustments(id: number) {
  return useQuery({
    queryKey: ['workers', id, 'shift-adjustments'],
    queryFn:  () =>
      apiClient.get<{ data: ShiftAdjustment[] }>(
        `/workers/${id}/shift-adjustments`
      ),
    enabled: id > 0,
  });
}
