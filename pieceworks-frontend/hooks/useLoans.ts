import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, type ApiEnvelope, type PaginatedEnvelope } from '@/lib/api-client';
import type { Loan } from '@/types/pieceworks';

export const loanKeys = {
  all:    ['loans'] as const,
  list:   (filters?: Record<string, unknown>) => [...loanKeys.all, 'list', filters ?? {}] as const,
  detail: (id: number) => [...loanKeys.all, id] as const,
};

export function useLoans(params?: { worker_id?: number; status?: string }) {
  return useQuery({
    queryKey: loanKeys.list(params),
    queryFn: () => {
      const qs = new URLSearchParams();
      if (params?.worker_id) qs.set('worker_id', String(params.worker_id));
      if (params?.status)    qs.set('status', params.status);
      const q = qs.toString();
      return apiClient.get<PaginatedEnvelope<Loan>>(`/loans${q ? '?' + q : ''}`);
    },
  });
}

export function useLoan(id: number | null | undefined) {
  return useQuery({
    queryKey: loanKeys.detail(id!),
    queryFn: () => apiClient.get<ApiEnvelope<Loan>>(`/loans/${id}`),
    enabled: id != null && id > 0,
  });
}

export function useCreateLoan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: {
      worker_id: number;
      amount: number;
      weekly_emi: number;
      disbursed_by: number;
      notes?: string;
    }) => apiClient.post<ApiEnvelope<Loan>>('/loans', body),
    onSuccess: () => qc.invalidateQueries({ queryKey: loanKeys.all }),
  });
}

export function useEarlySettleLoan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, settle_amount }: { id: number; settle_amount: number }) =>
      apiClient.post<ApiEnvelope<Loan>>(`/loans/${id}/early-settle`, { settle_amount }),
    onSuccess: () => qc.invalidateQueries({ queryKey: loanKeys.all }),
  });
}
