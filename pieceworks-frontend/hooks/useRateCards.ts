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

export interface StyleSku {
  id:              number;
  style_code:      string;
  style_name:      string;
  complexity_tier: ComplexityTier;
}

// ── Query keys ────────────────────────────────────────────────────────────────

export const rateCardKeys = {
  all:     ['rate-cards'] as const,
  list:    () => [...rateCardKeys.all, 'list'] as const,
  active:  () => [...rateCardKeys.all, 'active'] as const,
  entries: (id: number) => [...rateCardKeys.all, id, 'entries'] as const,
  skus:    () => ['style-skus'] as const,
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

/** All style SKUs with their complexity tier assignments. */
export function useStyleSkus() {
  return useQuery({
    queryKey: rateCardKeys.skus(),
    queryFn:  () => apiClient.get<{ data: StyleSku[] }>('/style-skus'),
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
