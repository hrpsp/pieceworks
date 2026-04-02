import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, type ApiEnvelope, type PaginatedEnvelope } from '@/lib/api-client';
import type { QCRejection } from '@/types/pieceworks';

export const rejectionKeys = {
  all:  ['rejections'] as const,
  list: (f?: Record<string, unknown>) => [...rejectionKeys.all, 'list', f ?? {}] as const,
};

export function useRejections(params?: { week_ref?: string; worker_id?: number; status?: string }) {
  return useQuery({
    queryKey: rejectionKeys.list(params),
    queryFn: () => {
      const qs = new URLSearchParams();
      if (params?.week_ref)  qs.set('week_ref', params.week_ref);
      if (params?.worker_id) qs.set('worker_id', String(params.worker_id));
      if (params?.status)    qs.set('status', params.status);
      const q = qs.toString();
      return apiClient.get<PaginatedEnvelope<QCRejection>>(`/rejections${q ? '?' + q : ''}`);
    },
  });
}

export function useCreateRejection() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: {
      production_record_id: number;
      worker_id: number;
      work_date: string;
      pairs_rejected: number;
      defect_type?: string;
      penalty_type: string;
    }) => apiClient.post<ApiEnvelope<QCRejection>>('/rejections', body),
    onSuccess: () => qc.invalidateQueries({ queryKey: rejectionKeys.all }),
  });
}

export function useDisputeRejection() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, dispute_reason }: { id: number; dispute_reason: string }) =>
      apiClient.patch<ApiEnvelope<QCRejection>>(`/rejections/${id}/dispute`, { dispute_reason }),
    onSuccess: () => qc.invalidateQueries({ queryKey: rejectionKeys.all }),
  });
}

export function useResolveRejection() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, resolution, notes }: { id: number; resolution: 'accept' | 'reverse'; notes?: string }) =>
      apiClient.patch<ApiEnvelope<QCRejection>>(`/rejections/${id}/resolve`, { resolution, notes }),
    onSuccess: () => qc.invalidateQueries({ queryKey: rejectionKeys.all }),
  });
}
