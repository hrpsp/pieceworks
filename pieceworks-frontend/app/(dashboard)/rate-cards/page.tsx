'use client';

import { useState, useEffect } from 'react';
import {
  useRateCards, useActiveRateCard, useRateCardEntries,
  useStyleSkus, usePatchSkuTier, useAddRateEntry, useGradeWageRates,
  type ComplexityTier, type StyleSku, type AddRateEntryPayload, type GradeWageRate,
} from '@/hooks/useRateCards';
import { Badge }     from '@/components/ui/badge';
import { Skeleton }  from '@/components/ui/skeleton';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { CreditCard, AlertCircle, CheckCircle2, Loader2, Search, X, Plus } from 'lucide-react';
import { Input } from '@/components/ui/input';
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

// ── Rate entry tier/grade options (must match backend validation) ──────────────
const ENTRY_TIER_OPTIONS: Array<{ value: string; label: string }> = [
  { value: 'standard', label: 'Standard' },
  { value: 'medium',   label: 'Medium'   },
  { value: 'complex',  label: 'Complex'  },
];

const GRADE_OPTIONS: Array<{ value: string; label: string }> = [
  { value: 'A',       label: 'Grade A'       },
  { value: 'B',       label: 'Grade B'       },
  { value: 'C',       label: 'Grade C'       },
  { value: 'D',       label: 'Grade D'       },
  { value: 'trainee', label: 'Trainee'       },
];

// ── Grade wage helpers ────────────────────────────────────────────────────────

const MIN_WAGE_WEEKLY = 8_545;

function formatPKR(amount: number): string {
  return `₨ ${amount.toLocaleString('en-PK', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
}

function formatGradeLabel(grade: string): string {
  return grade.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

// ── Add Rate Modal ────────────────────────────────────────────────────────────

interface AddRateModalProps {
  rateCardId: number;
  cardVersion: string;
  onClose: () => void;
}

function AddRateModal({ rateCardId, cardVersion, onClose }: AddRateModalProps) {
  const addEntry = useAddRateEntry(rateCardId);

  const [form, setForm] = useState<{
    task: string;
    worker_grade: string;
    complexity_tier: string;
    rate_pkr: string;
  }>({
    task:            '',
    worker_grade:    'A',
    complexity_tier: 'standard',
    rate_pkr:        '',
  });

  const [apiError, setApiError] = useState<string | null>(null);

  function handleChange(field: string, value: string) {
    setForm(f => ({ ...f, [field]: value }));
    setApiError(null);
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setApiError(null);

    const rate = parseFloat(form.rate_pkr);
    if (!form.task.trim())      return setApiError('Task name is required.');
    if (isNaN(rate) || rate <= 0) return setApiError('Rate must be a positive number.');

    addEntry.mutate(
      {
        task:            form.task.trim(),
        worker_grade:    form.worker_grade as AddRateEntryPayload['worker_grade'],
        complexity_tier: form.complexity_tier as AddRateEntryPayload['complexity_tier'],
        rate_pkr:        rate,
      },
      {
        onSuccess: () => onClose(),
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        onError: (err: any) => {
          setApiError(
            err?.response?.data?.message ?? err?.message ?? 'Failed to add rate entry.'
          );
        },
      }
    );
  }

  return (
    /* Backdrop */
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
      onClick={e => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-card rounded-xl border border-border shadow-xl w-full max-w-md mx-4 overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-border">
          <div>
            <h2 className="font-semibold text-foreground">Add Rate Entry</h2>
            <p className="text-xs text-muted-foreground mt-0.5">Rate card {cardVersion}</p>
          </div>
          <button
            onClick={onClose}
            className="text-muted-foreground hover:text-foreground transition-colors"
          >
            <X size={16}/>
          </button>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="p-5 space-y-4">

          {/* Task */}
          <div className="space-y-1.5">
            <label className="text-xs font-medium text-foreground">Task Name</label>
            <Input
              value={form.task}
              onChange={e => handleChange('task', e.target.value)}
              placeholder="e.g. Stitching, Lasting, Cementing…"
              className="h-9 text-sm"
              autoFocus
            />
          </div>

          {/* Grade + Tier in a row */}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <label className="text-xs font-medium text-foreground">Worker Grade</label>
              <select
                value={form.worker_grade}
                onChange={e => handleChange('worker_grade', e.target.value)}
                className="w-full h-9 rounded-md border border-input bg-background px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              >
                {GRADE_OPTIONS.map(g => (
                  <option key={g.value} value={g.value}>{g.label}</option>
                ))}
              </select>
            </div>

            <div className="space-y-1.5">
              <label className="text-xs font-medium text-foreground">Complexity Tier</label>
              <select
                value={form.complexity_tier}
                onChange={e => handleChange('complexity_tier', e.target.value)}
                className="w-full h-9 rounded-md border border-input bg-background px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              >
                {ENTRY_TIER_OPTIONS.map(t => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </select>
            </div>
          </div>

          {/* Rate PKR */}
          <div className="space-y-1.5">
            <label className="text-xs font-medium text-foreground">Rate (PKR per piece)</label>
            <div className="relative">
              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground text-sm">₨</span>
              <Input
                type="number"
                step="0.01"
                min="0.01"
                value={form.rate_pkr}
                onChange={e => handleChange('rate_pkr', e.target.value)}
                placeholder="0.00"
                className="h-9 pl-8 text-sm font-mono"
              />
            </div>
          </div>

          {/* Error */}
          {apiError && (
            <div className="flex items-start gap-2 text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
              <AlertCircle size={13} className="mt-0.5 shrink-0"/>
              <span>{apiError}</span>
            </div>
          )}

          {/* Actions */}
          <div className="flex items-center justify-end gap-2 pt-1">
            <Button type="button" variant="ghost" size="sm" onClick={onClose} disabled={addEntry.isPending}>
              Cancel
            </Button>
            <Button
              type="submit"
              size="sm"
              className="bg-brand-dark text-white hover:bg-brand-dark/90 gap-1.5"
              disabled={addEntry.isPending}
            >
              {addEntry.isPending ? (
                <><Loader2 size={13} className="animate-spin"/> Saving…</>
              ) : (
                <><Plus size={13}/> Add Entry</>
              )}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function RateCardsPage() {
  const [selectedId,    setSelectedId]    = useState<string>('');
  const [activeTier,    setActiveTier]    = useState<string>('all');
  const [taskSearch,    setTaskSearch]    = useState<string>('');
  const [showAddRate,   setShowAddRate]   = useState<boolean>(false);

  const canManage = usePermission(PERMISSIONS.RATE_CARDS_MANAGE);

  const rateCards  = useRateCards();
  const activeCard = useActiveRateCard();
  const entries    = useRateCardEntries(selectedId ? Number(selectedId) : null);
  const skus       = useStyleSkus();
  const patchTier  = usePatchSkuTier();

  const activeCardId = (activeCard.data as any)?.data?.id ?? null;
  const gradeWages   = useGradeWageRates(activeCardId);
  const gradeWageList: GradeWageRate[] = (gradeWages.data as any)?.data ?? [];

  // Auto-select: prefer the active rate card, fall back to the most recent one.
  // Note: cardList is declared below — use rateCards.data directly here to avoid TDZ.
  useEffect(() => {
    if (!selectedId) {
      const activeId   = (activeCard.data as any)?.data?.id;
      const list       = (rateCards.data as any)?.data ?? [];
      const fallbackId = list[0]?.id;
      const autoId     = activeId ?? fallbackId;
      if (autoId) setSelectedId(String(autoId));
    }
  }, [activeCard.data, rateCards.data, selectedId]);

  const cardList   = rateCards.data?.data ?? [];
  const selectedCard = cardList.find(c => c.id === Number(selectedId));
  const entryList  = entries.data?.data ?? [];

  const { tasks: allTasks, grades, tiers, matrix } = buildMatrix(entryList);

  // Client-side task filter
  const tasks = taskSearch.trim()
    ? allTasks.filter(t => t.toLowerCase().includes(taskSearch.trim().toLowerCase()))
    : allTasks;

  // Backend wraps all responses in { status, message, data: payload }.
  // StyleSku index returns payload = { data: StyleSku[], counts: {...} }, so the
  // real array lives two levels deep: .data (outer envelope) → .data (payload) → .data (items).
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const skuList: StyleSku[] = (skus.data as any)?.data?.data ?? [];
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
                    {c.version.startsWith('v') ? c.version : `v${c.version}`} — {c.effective_date}
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
              onClick={() => setShowAddRate(true)}
            >
              <Plus size={14}/> Add Rate
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

      {/* Tier filter tabs + task search */}
      {entryList.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-3">
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
          {/* Task search */}
          <div className="relative">
            <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none"/>
            <Input
              value={taskSearch}
              onChange={e => setTaskSearch(e.target.value)}
              placeholder="Filter by task…"
              className="pl-8 h-8 text-xs w-44"
            />
            {taskSearch && (
              <button
                onClick={() => setTaskSearch('')}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
              >
                <X size={11}/>
              </button>
            )}
          </div>
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
          {tasks.length === 0 && taskSearch && (
            <div className="p-8 text-center text-sm text-muted-foreground">
              No tasks matching &ldquo;{taskSearch}&rdquo;.
            </div>
          )}
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

      {/* Add Rate Modal */}
      {showAddRate && selectedId && selectedCard && (
        <AddRateModal
          rateCardId={Number(selectedId)}
          cardVersion={selectedCard.version.startsWith('v') ? selectedCard.version : `v${selectedCard.version}`}
          onClose={() => setShowAddRate(false)}
        />
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

      {/* Grade Daily Wages */}
      <div className="bg-white rounded-xl shadow-sm p-5">
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-bold text-sm" style={{ color: '#322E53' }}>Grade Daily Wages</h2>
          <PermissionGate permission={PERMISSIONS.RATE_CARDS_MANAGE}>
            <Button
              variant="outline"
              size="sm"
              className="h-7 text-xs border-brand-dark text-brand-dark"
            >
              Edit Wages
            </Button>
          </PermissionGate>
        </div>

        {gradeWages.isPending ? (
          <div className="space-y-2">
            {Array.from({ length: 5 }).map((_, i) => (
              <Skeleton key={i} className="h-8 w-full" />
            ))}
          </div>
        ) : gradeWageList.length === 0 ? (
          <p className="text-sm text-muted-foreground py-4">
            No grade wage rates found for the active rate card.
          </p>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/40">
                {['Grade', 'Daily Wage (PKR)', 'Weekly (×6 days)', 'Min Wage Floor'].map(h => (
                  <th
                    key={h}
                    className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                  >
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {gradeWageList.map(row => {
                const daily  = parseFloat(row.daily_wage_pkr);
                const weekly = daily * 6;
                const aboveFloor = weekly >= MIN_WAGE_WEEKLY;
                return (
                  <tr key={row.id} className="border-b border-border last:border-0 hover:bg-muted/20">
                    <td className="px-4 py-3 font-medium text-foreground">
                      {formatGradeLabel(row.grade)}
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-foreground">
                      {formatPKR(daily)}
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                      {formatPKR(weekly)}
                    </td>
                    <td className="px-4 py-3">
                      {aboveFloor ? (
                        <span className="flex items-center gap-1 text-green-700 text-xs font-medium">
                          <CheckCircle2 size={12}/> Above Floor
                        </span>
                      ) : (
                        <span className="flex items-center gap-1 text-red-600 text-xs font-medium">
                          <X size={12}/> Below Floor
                        </span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}

        <p className="text-xs text-gray-500 mt-3">
          Used by <span className="font-mono">DAILY_GRADE</span> and{' '}
          <span className="font-mono">HYBRID</span> production units.{' '}
          <span className="font-mono">PER_PAIR</span> units use the rate matrix above.
        </p>
      </div>

    </div>
  );
}
