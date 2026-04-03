'use client';

import { useState }         from 'react';
import {
  useBataStatus,
  useSyncNow,
  useStagingRecords,
  useUnmappedWorkers,
  useMapWorker,
  useAcceptStagingRecord,
  useHoldStagingRecord,
} from '@/hooks/useBataIntegration';
import { useWorkers }        from '@/hooks/useWorkers';
import { useQuery }          from '@tanstack/react-query';
import { apiClient }         from '@/lib/api-client';
import { Badge }             from '@/components/ui/badge';
import { Button }            from '@/components/ui/button';
import { Skeleton }          from '@/components/ui/skeleton';
import { StatCard }          from '@/components/pieceworks/StatCard';
import { Input }             from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Tabs, TabsContent, TabsList, TabsTrigger,
} from '@/components/ui/tabs';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  RefreshCw, CheckCircle2, AlertTriangle, XCircle,
  Clock, WifiOff, ArrowRightLeft, MapPin, Activity,
  ChevronRight, Loader2, CheckCheck, PauseCircle,
} from 'lucide-react';
import type { SyncRunStatus } from '@/types/pieceworks';

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmtDt(iso: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleString('en-PK', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: true,
  });
}

function fmtDate(iso: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleDateString('en-PK', { day: '2-digit', month: 'short', year: 'numeric' });
}

function todayISO(): string {
  return new Date().toISOString().split('T')[0];
}

// ── Status pill for sync health ───────────────────────────────────────────────

const SYNC_CONFIG: Record<SyncRunStatus, { label: string; icon: React.ReactNode; cls: string }> = {
  idle:     { label: 'Idle',     icon: <Clock       size={13} />, cls: 'bg-slate-100  text-slate-600' },
  syncing:  { label: 'Syncing',  icon: <Loader2     size={13} className="animate-spin" />, cls: 'bg-blue-50 text-blue-600' },
  success:  { label: 'Healthy',  icon: <CheckCircle2 size={13} />, cls: 'bg-green-50 text-green-600' },
  failed:   { label: 'Failed',   icon: <XCircle     size={13} />, cls: 'bg-red-50 text-red-600' },
};

function SyncBadge({ status }: { status: SyncRunStatus }) {
  const cfg = SYNC_CONFIG[status] ?? SYNC_CONFIG.idle;
  return (
    <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${cfg.cls}`}>
      {cfg.icon} {cfg.label}
    </span>
  );
}

// ── Staging status badge ──────────────────────────────────────────────────────

// Keyed by actual validation_status values from bata_api_staging table
const STAGING_BADGE: Record<string, { label: string; cls: string }> = {
  clean:    { label: 'Clean',    cls: 'bg-green-50  text-green-700  border-green-200' },
  warning:  { label: 'Warning',  cls: 'bg-amber-50  text-amber-700  border-amber-200' },
  error:    { label: 'Error',    cls: 'bg-red-50    text-red-700    border-red-200'   },
  held:     { label: 'Held',     cls: 'bg-slate-100 text-slate-600  border-slate-200' },
  mapped:   { label: 'Mapped',   cls: 'bg-purple-50 text-purple-700 border-purple-200'},
};

function StagingBadge({ status }: { status: string }) {
  const cfg = STAGING_BADGE[status] ?? { label: status ?? '—', cls: 'bg-slate-100 text-slate-500 border-slate-200' };
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border ${cfg.cls}`}>
      {cfg.label}
    </span>
  );
}

// ── Reconciliation panel ──────────────────────────────────────────────────────

interface ReconResponse {
  status: string;
  data?: {
    totals?: {
      records: number;
      pairs: number;
      earnings: number;
      pending_count: number;
      flagged_count: number;
    };
    active_rate_card?: { id: number; version: string; effective_date: string } | null;
    needs_review?: unknown[];
  };
}

function ReconciliationPanel({ date }: { date: string }) {
  const recon = useQuery({
    queryKey: ['bata-recon', date],
    queryFn:  () => apiClient.post<ReconResponse>(`/integration/bata/reconciliation/${date}`, {}),
    enabled:  !!date,
  });

  if (recon.isPending) {
    return (
      <div className="space-y-2 mt-4">
        {[1, 2, 3].map(i => <Skeleton key={i} className="h-10 w-full rounded-lg" />)}
      </div>
    );
  }

  const t = recon.data?.data?.totals;
  const rc = recon.data?.data?.active_rate_card;
  const needsReview = recon.data?.data?.needs_review ?? [];

  if (!t) {
    return (
      <div className="mt-4 rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
        No reconciliation data for {fmtDate(date)}.
      </div>
    );
  }

  return (
    <div className="mt-4 space-y-4">
      {/* Rate card indicator */}
      {rc && (
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <CheckCircle2 size={13} className="text-green-500" />
          Active Rate Card: <span className="font-medium text-foreground">{rc.version}</span>
          <span>(effective {fmtDate(rc.effective_date)})</span>
        </div>
      )}

      {/* Summary grid */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[
          { label: 'Records',  value: t.records },
          { label: 'Pairs',    value: t.pairs.toLocaleString() },
          { label: 'Pending',  value: t.pending_count,  warn: t.pending_count > 0 },
          { label: 'Flagged',  value: t.flagged_count,  warn: t.flagged_count > 0 },
        ].map(({ label, value, warn }) => (
          <div key={label} className={`rounded-lg border p-3 ${warn ? 'border-amber-200 bg-amber-50' : 'border-border bg-muted/30'}`}>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={`text-xl font-bold mt-0.5 ${warn ? 'text-amber-700' : 'text-foreground'}`}>{value}</p>
          </div>
        ))}
      </div>

      {/* Needs review list */}
      {needsReview.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
          <p className="text-xs font-semibold text-amber-700 mb-1.5 flex items-center gap-1.5">
            <AlertTriangle size={13} /> {needsReview.length} record(s) need review
          </p>
        </div>
      )}
    </div>
  );
}

// ── Worker Mapping Dialog ─────────────────────────────────────────────────────

function MapWorkerDialog({
  externalId,
  sampleCount,
  open,
  onClose,
}: {
  externalId: string;
  sampleCount: number;
  open: boolean;
  onClose: () => void;
}) {
  const [search, setSearch] = useState('');
  const workers  = useWorkers({ search, per_page: 20 });
  const mapWorker = useMapWorker();

  async function handleMap(pieceworksId: number) {
    await mapWorker.mutateAsync({ external_worker_id: externalId, pieceworks_worker_id: pieceworksId });
    onClose();
  }

  return (
    <Dialog open={open} onOpenChange={v => { if (!v) onClose(); }}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle className="text-base">Map External Worker</DialogTitle>
        </DialogHeader>
        <div className="space-y-3 pt-1">
          <div className="rounded-lg bg-muted/50 border border-border px-3 py-2 text-sm">
            <p className="text-xs text-muted-foreground mb-0.5">External Worker ID</p>
            <p className="font-mono font-medium">{externalId}</p>
            <p className="text-xs text-muted-foreground mt-1">{sampleCount} record(s) in staging</p>
          </div>
          <div>
            <p className="text-xs font-medium text-muted-foreground mb-1.5">Search PieceWorks workers</p>
            <Input
              placeholder="Name or CNIC…"
              value={search}
              onChange={e => setSearch(e.target.value)}
              className="h-8 text-sm"
            />
          </div>
          <div className="max-h-56 overflow-y-auto divide-y divide-border rounded-lg border border-border">
            {workers.isPending ? (
              <div className="p-3 text-sm text-muted-foreground text-center">Loading…</div>
            ) : ((workers.data as any)?.data?.data ?? []).length === 0 ? (
              <div className="p-3 text-sm text-muted-foreground text-center">No workers found</div>
            ) : (
              ((workers.data as any)?.data?.data ?? []).map((w: any) => (
                <button
                  key={w.id}
                  className="w-full flex items-center justify-between px-3 py-2 text-sm hover:bg-muted/50 transition-colors"
                  onClick={() => handleMap(w.id)}
                  disabled={mapWorker.isPending}
                >
                  <span>
                    <span className="font-medium">{w.name}</span>
                    <span className="ml-2 text-xs text-muted-foreground">{w.cnic}</span>
                  </span>
                  <ChevronRight size={14} className="text-muted-foreground" />
                </button>
              ))
            )}
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function IntegrationPage() {
  const today = todayISO();

  // ── Filters ────────────────────────────────────────────────────────────────
  const [stagingDate,   setStagingDate]   = useState(today);
  const [stagingStatus, setStagingStatus] = useState<string>('');
  const [reconDate,     setReconDate]     = useState(today);
  const [mapTarget,     setMapTarget]     = useState<{ externalId: string; count: number } | null>(null);

  // ── Data ───────────────────────────────────────────────────────────────────
  const statusQ    = useBataStatus();
  const staging    = useStagingRecords(stagingDate, stagingStatus || undefined);
  const unmapped   = useUnmappedWorkers();
  const syncNow    = useSyncNow();
  const acceptApi  = useAcceptStagingRecord();
  const acceptMan  = useAcceptStagingRecord();
  const hold       = useHoldStagingRecord();

  const s = statusQ.data?.data;

  return (
    <div className="p-6 space-y-6 max-w-6xl">
      {/* ── Header ────────────────────────────────────────────────────────── */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-foreground">Bata Integration</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Production data sync, staging review, and worker mapping
          </p>
        </div>
        <Button
          size="sm"
          onClick={() => syncNow.mutate()}
          disabled={syncNow.isPending || s?.status === 'syncing'}
          className="bg-brand-dark hover:bg-brand-mid text-white"
        >
          {syncNow.isPending ? (
            <><Loader2 size={14} className="mr-1.5 animate-spin" /> Syncing…</>
          ) : (
            <><RefreshCw size={14} className="mr-1.5" /> Sync Now</>
          )}
        </Button>
      </div>

      {/* ── Sync status cards ─────────────────────────────────────────────── */}
      {statusQ.isPending ? (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          {[1, 2, 3, 4].map(i => <Skeleton key={i} className="h-24 rounded-xl" />)}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div className="bg-card rounded-xl border border-border p-5">
            <p className="text-xs text-muted-foreground font-medium uppercase tracking-wide">Sync Status</p>
            <div className="mt-2">
              <SyncBadge status={s?.status ?? 'idle'} />
            </div>
            <p className="text-xs text-muted-foreground mt-2">
              Last: {fmtDt(s?.last_sync_at ?? null)}
            </p>
          </div>
          <StatCard
            label="Records Pulled"
            value={s?.records_pulled ?? 0}
            sub={`Next: ${fmtDt(s?.next_scheduled_at ?? null)}`}
            icon={Activity}
            accent
          />
          <StatCard
            label="Mapped"
            value={s?.records_mapped ?? 0}
            sub={`${s?.records_pending ?? 0} pending`}
            icon={ArrowRightLeft}
          />
          <StatCard
            label="Failures"
            value={s?.consecutive_failures ?? 0}
            sub={s?.consecutive_failures ? 'Check logs' : 'All clear'}
            icon={s?.consecutive_failures ? AlertTriangle : CheckCircle2}
            warning={!!s?.consecutive_failures}
          />
        </div>
      )}

      {/* ── Tabs ──────────────────────────────────────────────────────────── */}
      <Tabs defaultValue="staging">
        <TabsList className="bg-muted/50">
          <TabsTrigger value="staging">Staging Records</TabsTrigger>
          <TabsTrigger value="mapping">
            Worker Mapping
            {((unmapped.data as any)?.data?.unmapped_count ?? 0) > 0 && (
              <span className="ml-1.5 inline-flex items-center justify-center w-4 h-4 rounded-full bg-amber-500 text-white text-[10px] font-bold">
                {(unmapped.data as any)?.data?.unmapped_count}
              </span>
            )}
          </TabsTrigger>
          <TabsTrigger value="reconciliation">Reconciliation</TabsTrigger>
        </TabsList>

        {/* ── Staging tab ─────────────────────────────────────────────────── */}
        <TabsContent value="staging" className="mt-4 space-y-3">
          {/* Filters */}
          <div className="flex flex-wrap gap-2 items-center">
            <Input
              type="date"
              value={stagingDate}
              onChange={e => setStagingDate(e.target.value)}
              className="h-8 w-40 text-sm"
            />
            <Select value={stagingStatus || 'all'} onValueChange={v => setStagingStatus(v === 'all' ? '' : v)}>
              <SelectTrigger className="h-8 w-36 text-sm">
                <SelectValue placeholder="All statuses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All statuses</SelectItem>
                <SelectItem value="clean">Clean</SelectItem>
                <SelectItem value="warning">Warning</SelectItem>
                <SelectItem value="error">Error</SelectItem>
                <SelectItem value="held">Held</SelectItem>
                <SelectItem value="mapped">Mapped</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Table */}
          <div className="rounded-xl border border-border overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs text-muted-foreground uppercase tracking-wide">
                <tr>
                  <th className="px-4 py-3 text-left font-medium">Bata Worker ID</th>
                  <th className="px-4 py-3 text-left font-medium">PW Worker</th>
                  <th className="px-4 py-3 text-left font-medium">Style / SKU</th>
                  <th className="px-4 py-3 text-right font-medium">Pairs</th>
                  <th className="px-4 py-3 text-left font-medium">Shift</th>
                  <th className="px-4 py-3 text-left font-medium">Status</th>
                  <th className="px-4 py-3 text-left font-medium">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {staging.isPending ? (
                  [...Array(5)].map((_, i) => (
                    <tr key={i}>
                      {[...Array(7)].map((__, j) => (
                        <td key={j} className="px-4 py-3">
                          <Skeleton className="h-4 w-full rounded" />
                        </td>
                      ))}
                    </tr>
                  ))
                ) : ((staging.data as any)?.data?.data ?? []).length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-10 text-center text-sm text-muted-foreground">
                      No staging records found for {fmtDate(stagingDate)}.
                    </td>
                  </tr>
                ) : (
                  ((staging.data as any)?.data?.data ?? []).map(rec => (
                    <tr key={rec.id} className="hover:bg-muted/30 transition-colors">
                      <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{(rec as any).external_worker_id ?? rec.bata_worker_id ?? '—'}</td>
                      <td className="px-4 py-3 font-medium">
                        {(rec as any).worker ? (rec as any).worker.name : (
                          <span className="text-xs text-amber-600 flex items-center gap-1">
                            <WifiOff size={11} /> Unmapped
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <span className="font-medium">{rec.style_code}</span>
                        {(rec as any).sku_code && <span className="ml-1 text-xs text-muted-foreground">{(rec as any).sku_code}</span>}
                      </td>
                      <td className="px-4 py-3 text-right font-medium">{(rec as any).pairs_completed ?? rec.pairs_produced ?? 0}</td>
                      <td className="px-4 py-3 capitalize text-muted-foreground text-xs">
                        {(rec as any).shift ?? rec.source_shift ?? '—'}
                      </td>
                      <td className="px-4 py-3">
                        <StagingBadge status={(rec as any).validation_status ?? rec.status} />
                        {(rec as any).validation_errors && (
                          <p className="text-xs text-red-600 mt-0.5">
                            {JSON.stringify((rec as any).validation_errors)}
                          </p>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        {(['clean', 'warning', 'held'] as string[]).includes((rec as any).validation_status ?? rec.status) ? (
                          <div className="flex items-center gap-1">
                            <button
                              className="text-xs text-green-600 hover:text-green-700 font-medium flex items-center gap-0.5 disabled:opacity-50"
                              onClick={() => acceptApi.mutate({ id: rec.id, type: 'api' })}
                              disabled={acceptApi.isPending}
                              title="Accept via API"
                            >
                              <CheckCheck size={12} /> Accept
                            </button>
                            <span className="text-muted-foreground">·</span>
                            <button
                              className="text-xs text-slate-500 hover:text-slate-700 font-medium flex items-center gap-0.5 disabled:opacity-50"
                              onClick={() => hold.mutate(rec.id)}
                              disabled={hold.isPending}
                              title="Put on hold"
                            >
                              <PauseCircle size={12} /> Hold
                            </button>
                          </div>
                        ) : (
                          <span className="text-xs text-muted-foreground">—</span>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination summary */}
          {(staging.data as any)?.data?.total != null && (
            <p className="text-xs text-muted-foreground">
              Showing {((staging.data as any)?.data?.data ?? []).length} of {(staging.data as any)?.data?.total} records
            </p>
          )}
        </TabsContent>

        {/* ── Worker Mapping tab ──────────────────────────────────────────── */}
        <TabsContent value="mapping" className="mt-4">
          <div className="rounded-xl border border-border overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs text-muted-foreground uppercase tracking-wide">
                <tr>
                  <th className="px-4 py-3 text-left font-medium">Bata External ID</th>
                  <th className="px-4 py-3 text-right font-medium">Staging Records</th>
                  <th className="px-4 py-3 text-left font-medium">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {unmapped.isPending ? (
                  [...Array(4)].map((_, i) => (
                    <tr key={i}>
                      {[...Array(3)].map((__, j) => (
                        <td key={j} className="px-4 py-3">
                          <Skeleton className="h-4 w-full rounded" />
                        </td>
                      ))}
                    </tr>
                  ))
                ) : ((unmapped.data as any)?.data?.items ?? []).length === 0 ? (
                  <tr>
                    <td colSpan={3} className="px-4 py-10 text-center text-sm text-muted-foreground">
                      <CheckCircle2 size={20} className="mx-auto mb-2 text-green-500" />
                      All external workers are mapped.
                    </td>
                  </tr>
                ) : (
                  ((unmapped.data as any)?.data?.items ?? []).map((w: any) => (
                    <tr key={w.external_worker_id} className="hover:bg-muted/30 transition-colors">
                      <td className="px-4 py-3 font-mono text-sm">{w.external_worker_id}</td>
                      <td className="px-4 py-3 text-right">
                        <span className="font-medium">{w.staging_count}</span>
                      </td>
                      <td className="px-4 py-3">
                        <Button
                          size="sm"
                          variant="outline"
                          className="h-7 text-xs"
                          onClick={() => setMapTarget({ externalId: w.external_worker_id, count: w.staging_count })}
                        >
                          <MapPin size={12} className="mr-1" /> Map Worker
                        </Button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </TabsContent>

        {/* ── Reconciliation tab ──────────────────────────────────────────── */}
        <TabsContent value="reconciliation" className="mt-4">
          <div className="flex items-center gap-3">
            <div>
              <p className="text-xs text-muted-foreground mb-1">Reconciliation Date</p>
              <Input
                type="date"
                value={reconDate}
                onChange={e => setReconDate(e.target.value)}
                className="h-8 w-44 text-sm"
              />
            </div>
          </div>
          <ReconciliationPanel date={reconDate} />
        </TabsContent>
      </Tabs>

      {/* ── Map worker dialog ─────────────────────────────────────────────── */}
      {mapTarget && (
        <MapWorkerDialog
          externalId={mapTarget.externalId}
          sampleCount={mapTarget.count}
          open={!!mapTarget}
          onClose={() => setMapTarget(null)}
        />
      )}
    </div>
  );
}
