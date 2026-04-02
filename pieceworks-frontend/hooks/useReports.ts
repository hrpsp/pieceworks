import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';

function buildQs(params: Record<string, string | number | undefined | null>): string {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
  }
  const s = qs.toString();
  return s ? '?' + s : '';
}

export function useDailyProductionReport(params: { date?: string; line_id?: number }) {
  return useQuery({
    queryKey: ['reports', 'daily-production', params],
    queryFn:  () => apiClient.get(`/reports/daily-production${buildQs(params)}`),
    enabled:  !!params.date,
  });
}

export function useWorkerEfficiencyReport(params: { week_ref?: string; contractor_id?: number }) {
  return useQuery({
    queryKey: ['reports', 'worker-efficiency', params],
    queryFn:  () => apiClient.get(`/reports/worker-efficiency${buildQs(params)}`),
    enabled:  !!params.week_ref,
  });
}

export function useWageCostPerPairReport(params: { week_ref?: string; style_sku_id?: number }) {
  return useQuery({
    queryKey: ['reports', 'wage-cost-per-pair', params],
    queryFn:  () => apiClient.get(`/reports/wage-cost-per-pair${buildQs(params)}`),
    enabled:  !!params.week_ref,
  });
}

export function useLineProductivityReport(params: { week_ref?: string }) {
  return useQuery({
    queryKey: ['reports', 'line-productivity', params],
    queryFn:  () => apiClient.get(`/reports/line-productivity${buildQs(params)}`),
    enabled:  !!params.week_ref,
  });
}

export function useMinWageComplianceReport(params: { week_ref?: string }) {
  return useQuery({
    queryKey: ['reports', 'min-wage-compliance', params],
    queryFn:  () => apiClient.get(`/reports/min-wage-compliance${buildQs(params)}`),
    enabled:  !!params.week_ref,
  });
}

export function useShiftAdjustmentsReport(params: { week_ref?: string }) {
  return useQuery({
    queryKey: ['reports', 'shift-adjustments', params],
    queryFn:  () => apiClient.get(`/reports/shift-adjustments${buildQs(params)}`),
    enabled:  !!params.week_ref,
  });
}

export function useGhostWorkerReport(params: { week_ref?: string }) {
  return useQuery({
    queryKey: ['reports', 'ghost-worker', params],
    queryFn:  () => apiClient.get(`/reports/ghost-worker${buildQs(params)}`),
    enabled:  !!params.week_ref,
  });
}

export function useContractorPerformanceReport(params: { month?: string }) {
  return useQuery({
    queryKey: ['reports', 'contractor-performance', params],
    queryFn:  () => apiClient.get(`/reports/contractor-performance${buildQs(params)}`),
    enabled:  !!params.month,
  });
}

export function useEobiChallanReport(params: { month?: number; year?: number }) {
  return useQuery({
    queryKey: ['reports', 'eobi-challan', params],
    queryFn:  () => apiClient.get(`/reports/eobi-challan${buildQs(params)}`),
    enabled:  !!(params.month && params.year),
  });
}

export function useTenureMilestonesReport(params: { upcoming_days?: number }) {
  return useQuery({
    queryKey: ['reports', 'tenure-milestones', params],
    queryFn:  () => apiClient.get(`/reports/tenure-milestones${buildQs(params)}`),
  });
}

export function useRejectionAnalysisReport(params: { month?: string }) {
  return useQuery({
    queryKey: ['reports', 'rejection-analysis', params],
    queryFn:  () => apiClient.get(`/reports/rejection-analysis${buildQs(params)}`),
    enabled:  !!params.month,
  });
}

export function useAnnualPayrollSummaryReport(params: { year?: number }) {
  return useQuery({
    queryKey: ['reports', 'annual-payroll-summary', params],
    queryFn:  () => apiClient.get(`/reports/annual-payroll-summary${buildQs(params)}`),
    enabled:  !!params.year,
  });
}
