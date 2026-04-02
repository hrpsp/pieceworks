import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, type ApiEnvelope, type PaginatedEnvelope } from '@/lib/api-client';
import type { WorkerCompliance, TenureMilestoneExt } from '@/types/pieceworks';

export function useEobiReport(month: number | null, year: number | null) {
  return useQuery({
    queryKey: ['compliance', 'eobi', month, year],
    queryFn:  () => apiClient.get(`/compliance/eobi-report?month=${month}&year=${year}`),
    enabled:  !!(month && year),
  });
}

export function useWhtReport(year: number | null) {
  return useQuery({
    queryKey: ['compliance', 'wht', year],
    queryFn:  () => apiClient.get(`/compliance/wht-report?year=${year}`),
    enabled:  !!year,
  });
}

export function useTenureMilestones() {
  return useQuery({
    queryKey: ['compliance', 'tenure-milestones'],
    queryFn:  () => apiClient.get<ApiEnvelope<TenureMilestoneExt[]>>('/compliance/tenure-milestones'),
  });
}

export function useMissingRegistrations() {
  return useQuery({
    queryKey: ['compliance', 'missing-registrations'],
    queryFn:  () => apiClient.get<ApiEnvelope<{ worker_id: number; name: string; missing: string[] }[]>>('/compliance/missing-registrations'),
  });
}

export function useRegisterEobi() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { worker_id: number; eobi_number?: string; pessi_number?: string }) =>
      apiClient.post<ApiEnvelope<WorkerCompliance>>('/compliance/register-eobi', body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['compliance'] });
      qc.invalidateQueries({ queryKey: ['workers'] });
    },
  });
}
