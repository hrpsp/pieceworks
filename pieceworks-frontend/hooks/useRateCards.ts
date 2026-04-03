import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, type ApiEnvelope, type PaginatedEnvelope } from '@/lib/api-client';

// ── Types ─────────────────────────────────────────────────────────────────────

export interface RateCard {
  id:             number;
  version:        string;
  effective_date: string;
  is_active:      boolean;
  approved_by:    number | null;
  created_at:     string;
}

export interface RateCardEntry {
  id:               number;
  rate_card_id:     number;
  task:             string;
  complexity_tier:  'standard' | 'complex' | 'premium';
  worker_grade:     string;
  rate_pkr:         string;
}

export type ComplexityTier = 'standard' | 'complex' | 'premium';

export interface GradeWageRate {
  id:             number;
  rate_card_id:   number;
  grade:          string;
  daily_wage_pkr: string;   // decimal string from Laravel
}

export interface StyleSku {
  id:              number;
  style_code:      string;
  style_name:      string;
  complexity_tier: ComplexityTier;
}

// ── Query keys ────────────────────────────────────────────────────────────────

export const rateCardKeys = {
  all:        ['rate-cards'] as const,
  list:       () => [...rateCardKeys.all, 'list'] as const,
  active:     () => [...rateCardKeys.all, 'active'] as const,
  entries:    (id: number) => [...rateCardKeys.all, id, 'entries'] as const,
  gradeWages: (id: number) => [...rateCardKeys.all, id, 'grade-wages'] as const,
  skus:       () => ['style-skus'] as const,
};

// ── Hooks ─────────────────────────────────────────────────────────────────────

/** Full list of rate cards (all versions). */
export function useRateCards() {
  return useQuery({
    queryKey: rateCardKeys.list(),
    queryFn:  () => apiClient.get<{ data: RateCard[] }>('/rate-cards'),
  });
}

/** Currently active rate card (single). */
export function useActiveRateCard() {
  return useQuery({
    queryKey: rateCardKeys.active(),
    queryFn:  () => apiClient.get<ApiEnvelope<RateCard>>('/rate-cards/active'),
  });
}

/** Rate entries for a specific rate card. */
export function useRateCardEntries(id: number | null | undefined) {
  return useQuery({
    queryKey: rateCardKeys.entries(id!),
    queryFn:  () =>
      apiClient.get<{ data: RateCardEntry[] }>(`/rate-cards/${id}/entries`),
    enabled: id != null,
  });
}

/** Grade wage rates for a specific rate card. */
export function useGradeWageRates(rateCardId: number | null | undefined) {
  return useQuery({
    queryKey: rateCardKeys.gradeWages(rateCardId!),
    queryFn:  () =>
      apiClient.get<{ data: GradeWageRate[] }>(`/rate-cards/${rateCardId}/grade-wages`),
    enabled: rateCardId != null,
  });
}

/** All style SKUs with their complexity tier assignments. */
export function useStyleSkus() {
  return useQuery({
    queryKey: rateCardKeys.skus(),
    queryFn:  () => apiClient.get<{ data: StyleSku[] }>('/style-skus'),
  });
}

// ── Add rate entry ────────────────────────────────────────────────────────────

export interface AddRateEntryPayload {
  task:            string;
  complexity_tier: 'standard' | 'medium' | 'complex';
  worker_grade:    'A' | 'B' | 'C' | 'D' | 'trainee';
  rate_pkr:        number;
}

/**
 * POST /api/rate-cards/{id}/entries
 *
 * Manually add a single rate entry to an existing rate card.
 * On success, invalidates the entries query for that card so the matrix refreshes.
 */
export function useAddRateEntry(rateCardId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: AddRateEntryPayload) =>
      apiClient.post<ApiEnvelope<RateCardEntry>>(
        `/rate-cards/${rateCardId}/entries`,
        payload
      ),

    onSuccess: () => {
      if (rateCardId != null) {
        queryClient.invalidateQueries({ queryKey: rateCardKeys.entries(rateCardId) });
      }
    },
  });
}

// ── Grade wage upsert ─────────────────────────────────────────────────────────

export interface UpdateGradeWagesPayload {
  rateCardId: number;
  wages:      { grade: string; daily_wage: number }[];
}

/**
 * POST /api/rate-cards/{rateCardId}/grade-wages
 *
 * Bulk-upsert grade wage rates for the given rate card.
 * On success, invalidates the grade-wage-rates query for that card.
 */
export function useUpdateGradeWages() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ rateCardId, wages }: UpdateGradeWagesPayload) =>
      apiClient.post<ApiEnvelope<GradeWageRate[]>>(
        `/rate-cards/${rateCardId}/grade-wages`,
        { wages }
      ),

    onSuccess: (_data, { rateCardId }) => {
      queryClient.invalidateQueries({ queryKey: ['grade-wage-rates', rateCardId] });
      // Also invalidate the rateCardKeys-namespaced key used by the rate-cards page
      queryClient.invalidateQueries({ queryKey: rateCardKeys.gradeWages(rateCardId) });
    },
  });
}

/**
 * PATCH the complexity tier on a style SKU.
 * Uses optimistic update — reverts on error.
 */
export function usePatchSkuTier() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, tier }: { id: number; tier: ComplexityTier }) =>
      apiClient.patch<ApiEnvelope<StyleSku>>(`/style-skus/${id}/tier`, {
        complexity_tier: tier,
      }),

    onMutate: async ({ id, tier }) => {
      await queryClient.cancelQueries({ queryKey: rateCardKeys.skus() });

      const previous = queryClient.getQueryData<{ data: StyleSku[] }>(
        rateCardKeys.skus()
      );

      queryClient.setQueryData<{ data: StyleSku[] }>(
        rateCardKeys.skus(),
        old => old
          ? { ...old, data: old.data.map(s => s.id === id ? { ...s, complexity_tier: tier } : s) }
          : old
      );

      return { previous };
    },

    onError: (_err, _vars, ctx) => {
      if (ctx?.previous) {
        queryClient.setQueryData(rateCardKeys.skus(), ctx.previous);
      }
    },

    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: rateCardKeys.skus() });
    },
  });
}
