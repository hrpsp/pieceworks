import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, type ApiEnvelope, type PaginatedEnvelope } from '@/lib/api-client';
import type { Advance } from '@/types/pieceworks';

export const advanceKeys = {
  all:    ['advances'] as const,
  list:   (filters?: Record<string, unknown>) => [...advanceKeys.all, 'list', filters ?? {}] as const,
};

export function useAdvances(params?: { worker_id?: number; week_ref?: string; status?: string }) {
  return useQuery({
    queryKey: advanceKeys.list(params),
    queryFn: () => {
      const qs = new URLSearchParams();
      if (params?.worker_id) qs.set('worker_id', String(params.worker_id));
      if (params?.week_ref)  qs.set('week_ref', params.week_ref);
      if (params?.status)    qs.set('status', params.status);
      const q = qs.toString();
      return apiClient.get<PaginatedEnvelope<Advance>>(`/advances${q ? '?' + q : ''}`);
    },
  });
}

export function useCreateAdvance() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { worker_id: number; amount: number; payment_method: string; notes?: string }) =>
      apiClient.post<ApiEnvelope<Advance>>('/advances', body),
    onSuccess: () => qc.invalidateQueries({ queryKey: advanceKeys.all }),
  });
}

export function useApproveAdvance() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => apiClient.patch<ApiEnvelope<Advance>>(`/advances/${id}/approve`, {}),
    onSuccess: () => qc.invalidateQueries({ queryKey: advanceKeys.all }),
  });
}

export function useRejectAdvance() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => apiClient.patch<ApiEnvelope<Advance>>(`/advances/${id}/reject`, {}),
    onSuccess: () => qc.invalidateQueries({ queryKey: advanceKeys.all }),
  });
}
