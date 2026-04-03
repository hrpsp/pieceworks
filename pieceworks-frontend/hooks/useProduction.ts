import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient, type ApiEnvelope } from '@/lib/api-client';
import type { ProductionUnit } from '@/types/pieceworks';

// ── Domain types ─────────────────────────────────────────────────────────────

export type Shift = 'morning' | 'evening' | 'night';
export type SourceTag = 'bata_api' | 'manual_supervisor' | 'manual_backfill';
export type ValidationStatus = 'pending' | 'validated' | 'disputed' | 'rejected';

export interface ProductionRecord {
  id: number;
  worker_id: number;
  line_id: number;
  rate_card_entry_id: number | null;
  work_date: string;
  shift: Shift;
  style_sku_id: number | null;
  task: string;
  pairs_produced: number;
  rate_amount: string;
  gross_earnings: string;
  source_tag: SourceTag;
  shift_adjustment: string;
  shift_adj_authorized_by: number | null;
  shift_adj_reason: string | null;
  supervisor_notes: string | null;
  validation_status: ValidationStatus;
  is_locked: boolean;
  created_at: string;
  updated_at: string;
}

export interface BatchProductionRecord {
  worker_id: number;
  line_id: number;
  work_date: string;              // 'YYYY-MM-DD'
  shift: Shift;
  task: string;
  pairs_produced: number;
  style_sku_id?: number;
  source_tag?: SourceTag;
  shift_adjustment?: number;
  shift_adj_authorized_by?: number;
  shift_adj_reason?: string;
  supervisor_notes?: string;
}

export interface BatchProductionPayload {
  records: BatchProductionRecord[];
}

export interface BatchProductionResult {
  created_count: number;
  created_ids: number[];
  rate_warnings?: Array<{
    index: number;
    worker_id: number;
    reason: string;
  }>;
}

// ── Production Units ──────────────────────────────────────────────────────────

/**
 * Fetch production units scoped to a specific line.
 * Query is disabled while lineId is undefined.
 */
export function useProductionUnits(lineId: number | undefined) {
  return useQuery({
    queryKey: ['production-units', lineId],
    queryFn:  () =>
      apiClient.get<ApiEnvelope<ProductionUnit[]>>(`/lines/${lineId}/units`),
    enabled: !!lineId,
  });
}

// ── Hooks ─────────────────────────────────────────────────────────────────────

/**
 * Submit a batch of production records in a single transaction.
 * Resolves rates server-side before insert.
 *
 * @example
 * const batch = useProductionBatch();
 * batch.mutate({ records: [...] });
 *
 * // Check for partial rate failures:
 * // batch.data?.data.rate_warnings
 */
export function useProductionBatch() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: BatchProductionPayload) =>
      apiClient.post<ApiEnvelope<BatchProductionResult>>(
        '/production/batch',
        payload
      ),
    onSuccess: () => {
      // Invalidate daily view so any open production page reflects the new records
      queryClient.invalidateQueries({ queryKey: ['production'] });
    },
  });
}
