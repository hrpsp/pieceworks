import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, type ApiEnvelope, type PaginatedEnvelope } from '@/lib/api-client';
import type { BataApiSyncStatus, StagingRecord } from '@/types/pieceworks';

export const bataKeys = {
  status:   ['bata', 'status'] as const,
  staging:  (date?: string, status?: string) => ['bata', 'staging', date ?? '', status ?? ''] as const,
  unmapped: ['bata', 'unmapped'] as const,
};

export function useBataStatus() {
  return useQuery({
    queryKey: bataKeys.status,
    queryFn: () => apiClient.get<ApiEnvelope<BataApiSyncStatus>>('/integration/bata/status'),
    refetchInterval: 5 * 60 * 1000, // every 5 minutes
  });
}

export function useSyncNow() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiClient.post<ApiEnvelope<{ message: string }>>('/integration/bata/sync-now', {}),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: bataKeys.status });
      qc.invalidateQueries({ queryKey: ['bata', 'staging'] });
    },
  });
}

export function useStagingRecords(date?: string, status?: string) {
  return useQuery({
    queryKey: bataKeys.staging(date, status),
    queryFn: () => {
      const qs = new URLSearchParams();
      if (date)   qs.set('date', date);
      if (status) qs.set('status', status);
      const q = qs.toString();
      return apiClient.get<PaginatedEnvelope<StagingRecord>>(`/integration/bata/staging${q ? '?' + q : ''}`);
    },
  });
}

export function useUnmappedWorkers() {
  return useQuery({
    queryKey: bataKeys.unmapped,
    queryFn: () => apiClient.get<ApiEnvelope<{ external_worker_id: string; sample_records: number }[]>>('/integration/bata/unmapped-workers'),
  });
}

export function useMapWorker() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { external_worker_id: string; pieceworks_worker_id: number }) =>
      apiClient.post<ApiEnvelope<{ message: string }>>('/integration/bata/map-worker', body),
    onSuccess: () => qc.invalidateQueries({ queryKey: bataKeys.unmapped }),
  });
}

export function useAcceptStagingRecord() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, type }: { id: number; type: 'api' | 'manual' }) =>
      apiClient.patch<ApiEnvelope<StagingRecord>>(`/integration/bata/staging/${id}/accept-${type}`, {}),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['bata', 'staging'] }),
  });
}

export function useHoldStagingRecord() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) =>
      apiClient.patch<ApiEnvelope<StagingRecord>>(`/integration/bata/staging/${id}/hold`, {}),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['bata', 'staging'] }),
  });
}
