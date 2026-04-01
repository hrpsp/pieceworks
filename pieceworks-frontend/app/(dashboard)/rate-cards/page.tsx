'use client';

import { useState, useEffect } from 'react';
import {
  useRateCards, useActiveRateCard, useRateCardEntries,
  useStyleSkus, usePatchSkuTier,
  type ComplexityTier,
} from '@/hooks/useRateCards';
import { Badge }     from '@/components/ui/badge';
import { Skeleton }  from '@/components/ui/skeleton';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { CreditCard, AlertCircle, CheckCircle2, Settings, Loader2 } from 'lucide-react';
import { Button }                      from '@/components/ui/button';
import { PermissionGate, PERMISSIONS } from '@/lib/permissions';
import { usePermission }               from '@/lib/permissions';

// ── Types ─────────────────────────────────────────────────────────────────────

type Matrix = Record<string, Record<string, Record<string, number>>>;

// ── Build matrix from entries ─────────────────────────────────────────────────

function buildMatrix(entries: { task: string; worker_grade: string; complexity_tier: string; rate_pkr: string }[]) {
  const tasks  = Array.from(new Set(entries.map(e => e.task))).sort();
  const grades = Array.from(new Set(entries.map(e => e.worker_grade))).sort();
  const tiers  = Array.from(new Set(entries.map(e => e.complexity_tier))).sort();

  const matrix: Matrix = {};
  for (const e of entries) {
    matrix[e.task]                         ??= {};
    matrix[e.task][e.worker_grade]         ??= {};
    matrix[e.task][e.worker_grade][e.complexity_tier] = Number(e.rate_pkr);
  }

  return { tasks, grades, tiers, matrix };
}

const TIER_COLORS: Record<string, string> = {
  standard: 'bg-muted text-muted-foreground',
  complex:  'bg-brand-peach/20 text-brand-dark',
  premium:  'bg-brand-salmon/20 text-brand-dark',
};

const TIER_OPTIONS: ComplexityTier[] = ['standard', 'complex', 'premium'];

// ── Page ──────────────────────────────────────────────────────────────────────

export default function RateCardsPage() {
  const [selectedId, setSelectedId] = useState<string>('');
  const [activeTier, setActiveTier] = useState<string>('all');

  const canManage = usePermission(PERMISSIONS.RATE_CARDS_MANAGE);

  const rateCards  = useRateCards();
  const activeCard = useActiveRateCard();
  const entries    = useRateCardEntries(selectedId ? Number(selectedId) : null);
  const skus       = useStyleSkus();
  const patchTier  = usePatchSkuTier();

  // Auto-select the active rate card on load
  useEffect(() => {
    if (!selectedId && activeCard.data?.data?.id) {
      setSelectedId(String(activeCard.data.data.id));
    }
  }, [activeCard.data, selectedId]);

  const cardList   = rateCards.data?.data ?? [];
  const selectedCard = cardList.find(c => c.id === Number(selectedId));
  const entryList  = entries.data?.data ?? [];

  const { tasks, grades, tiers, matrix } = buildMatrix(entryList);

  const skuList = skus.data?.data ?? [];
  const tierCounts = skuList.reduce<Record<string, number>>((acc, s) => {
    acc[s.complexity_tier] = (acc[s.complexity_tier] ?? 0) + 1;
    return acc;
  }, {});

  return (
    <div className="p-6 space-y-6 max-w-7xl mx-auto">

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Rate Cards</h1>
          <p className="text-sm text-muted-foreground mt-0.5">PKR rates by task, grade, and complexity tier</p>
        </div>
        <div className="flex items-center gap-3">
          {rateCards.isPending ? (
            <Skeleton className="h-9 w-48"/>
          ) : rateCards.isError ? (
            <p className="text-xs text-muted-foreground">Rate cards API not yet built</p>
          ) : (
            <Select value={selectedId} onValueChange={setSelectedId}>
              <SelectTrigger className="h-9 w-56">
                <SelectValue placeholder="Select rate card…"/>
              </SelectTrigger>
              <SelectContent>
                {cardList.map(c => (
                  <SelectItem key={c.id} value={String(c.id)}>
                    v{c.version} — {c.effective_date}
                    {c.is_active && ' ✓'}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          <PermissionGate permission={PERMISSIONS.RATE_CARDS_MANAGE}>
            <Button
              variant="outline"
              className="gap-2 border-brand-dark text-brand-dark"
              disabled={!selectedId}
            >
              <Settings size={14}/> Manage
            </Button>
          </PermissionGate>
        </div>
      </div>

      {/* Selected card metadata */}
      {selectedCard && (
        <div className="bg-card rounded-xl border border-border p-4 flex items-center gap-6 text-sm">
          <div className="flex items-center gap-2">
            <CreditCard size={15} className="text-muted-foreground"/>
            <span className="font-semibold text-foreground">v{selectedCard.version}</span>
          </div>
          <div className="text-muted-foreground">Effective: {selectedCard.effective_date}</div>
          {selectedCard.is_active
            ? <Badge className="bg-green-100 text-green-700 border-0 gap-1"><CheckCircle2 size={11}/>Active</Badge>
            : <Badge className="bg-muted text-muted-foreground border-0">Inactive</Badge>}
          <div className="text-muted-foreground ml-auto">{entryList.length} rate entries</div>
        </div>
      )}

      {/* Tier filter tabs */}
      {entryList.length > 0 && (
        <div className="flex items-center gap-2">
          {['all', ...tiers].map(t => (
            <button
              key={t}
              onClick={() => setActiveTier(t)}
              className={`px-3 py-1 rounded-full text-xs font-medium transition-colors ${
                activeTier === t
                  ? 'bg-brand-dark text-white'
                  : 'bg-muted text-muted-foreground hover:bg-muted/80'
              }`}
            >
              {t === 'all' ? 'All tiers' : t}
            </button>
          ))}
        </div>
      )}

      {/* Rate matrix */}
      {!selectedId ? (
        <div className="bg-card rounded-xl border border-border p-12 text-center space-y-3">
          <CreditCard size={32} className="mx-auto text-muted-foreground/40"/>
          <p className="text-muted-foreground text-sm">
            {rateCards.isError
              ? 'Rate cards API endpoint (GET /rate-cards) needs to be built.'
              : 'Select a rate card to view its matrix.'}
          </p>
        </div>
      ) : entries.isPending ? (
        <Skeleton className="h-64 rounded-xl"/>
      ) : entryList.length === 0 ? (
        <div className="bg-card rounded-xl border border-border p-12 text-center">
          <p className="text-muted-foreground text-sm">No entries for this rate card.</p>
        </div>
      ) : (
        <div className="bg-card rounded-xl border border-border overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/40">
                  <th className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground sticky left-0 bg-muted/40 z-10 min-w-36">
                    Task
                  </th>
                  {grades.flatMap(g =>
                    tiers
                      .filter(t => activeTier === 'all' || t === activeTier)
                      .map(t => (
                        <th key={`${g}-${t}`}
                          className="px-3 py-2.5 text-left text-xs font-semibold text-muted-foreground whitespace-nowrap">
                          <div className="font-bold text-foreground">Grade {g}</div>
                          <div className={`mt-0.5 text-xs px-1.5 py-0.5 rounded-full inline-block ${TIER_COLORS[t]}`}>{t}</div>
                        </th>
                      ))
                  )}
                </tr>
              </thead>
              <tbody>
                {tasks.map(task => (
                  <tr key={task} className="border-b border-border last:border-0 hover:bg-muted/20">
                    <td className="px-4 py-3 font-medium text-foreground sticky left-0 bg-card z-10">{task}</td>
                    {grades.flatMap(g =>
                      tiers
                        .filter(t => activeTier === 'all' || t === activeTier)
                        .map(t => {
                          const rate = matrix[task]?.[g]?.[t];
                          return (
                            <td key={`${g}-${t}`} className="px-3 py-3 text-right font-mono text-xs">
                              {rate != null
                                ? <span className="text-foreground font-semibold">₨ {rate.toLocaleString()}</span>
                                : <span className="text-muted-foreground/40">—</span>}
                            </td>
                          );
                        })
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* SKU tier assignments */}
      <div className="bg-card rounded-xl border border-border overflow-hidden">
        <div className="px-5 py-3 border-b border-border flex items-center justify-between">
          <h2 className="font-semibold text-sm text-foreground">Style SKU Tier Assignments</h2>
          <div className="flex items-center gap-2">
            {Object.entries(tierCounts).map(([tier, count]) => (
              <Badge key={tier} className={`text-xs border-0 ${TIER_COLORS[tier] ?? 'bg-muted text-muted-foreground'}`}>
                {tier}: {count}
              </Badge>
            ))}
            {patchTier.isPending && (
              <Loader2 size={13} className="animate-spin text-muted-foreground"/>
            )}
          </div>
        </div>

        {skus.isPending ? (
          <div className="p-5 space-y-2">
            {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-8"/>)}
          </div>
        ) : skus.isError ? (
          <div className="p-5 flex items-center gap-2 text-muted-foreground text-sm">
            <AlertCircle size={14}/>
            Style SKU endpoint (GET /style-skus) not yet available.
          </div>
        ) : skuList.length === 0 ? (
          <div className="p-5 text-muted-foreground text-sm">No SKUs defined.</div>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/40">
                {['Style Code', 'Style Name', 'Complexity Tier'].map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {skuList.map(s => (
                <tr key={s.id} className="border-b border-border last:border-0 hover:bg-muted/20">
                  <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{s.style_code}</td>
                  <td className="px-4 py-3 font-medium text-foreground">{s.style_name}</td>
                  <td className="px-4 py-3">
                    {canManage ? (
                      /* Inline tier selector with optimistic update */
                      <select
                        value={s.complexity_tier}
                        onChange={e =>
                          patchTier.mutate({
                            id:   s.id,
                            tier: e.target.value as ComplexityTier,
                          })
                        }
                        disabled={patchTier.isPending}
                        className={`text-xs px-2 py-1 rounded-full border-0 font-medium cursor-pointer ${
                          TIER_COLORS[s.complexity_tier] ?? 'bg-muted text-muted-foreground'
                        }`}
                      >
                        {TIER_OPTIONS.map(t => (
                          <option key={t} value={t}>{t}</option>
                        ))}
                      </select>
                    ) : (
                      <Badge className={`text-xs border-0 capitalize ${TIER_COLORS[s.complexity_tier] ?? 'bg-muted text-muted-foreground'}`}>
                        {s.complexity_tier}
                      </Badge>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
