'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import { useProductionBatch, useProductionUnits } from '@/hooks/useProduction';
import { useWorkers }               from '@/hooks/useWorkers';
import type { ProductionUnit }      from '@/types/pieceworks';
import { WageModelBadge }           from '@/components/ui/WageModelBadge';
import { useSyncStatus }            from '@/hooks/useSyncStatus';
import {
  saveToQueue,
  getQueue,
  removeFromQueue,
  clearQueue,
  syncQueue,
  type ProductionSession,
} from '@/lib/offline-queue';
import { OfflineIndicator }         from '@/components/pieceworks/OfflineIndicator';
import { Button }                   from '@/components/ui/button';
import { Input }                    from '@/components/ui/input';
import { Label }                    from '@/components/ui/label';
import { Badge }                    from '@/components/ui/badge';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  AlertTriangle, CheckCircle2, Plus, Trash2,
  CloudUpload, WifiOff, Loader2, Send,
} from 'lucide-react';

// ── Row type ──────────────────────────────────────────────────────────────────

interface EntryRow {
  id:               string;
  worker_id:        string;
  task:             string;
  pairs_produced:   string;
  supervisor_notes: string;
}

function emptyRow(): EntryRow {
  return {
    id:               crypto.randomUUID(),
    worker_id:        '',
    task:             '',
    pairs_produced:   '',
    supervisor_notes: '',
  };
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function ProductionPage() {
  const today = new Date().toISOString().split('T')[0];

  const [lineId,        setLineId]        = useState('');
  const [productionUnitId, setProductionUnitId] = useState('');
  const [selectedUnit,  setSelectedUnit]  = useState<ProductionUnit | null>(null);
  const [shift,         setShift]         = useState<'morning'|'evening'|'night'>('morning');
  const [date,          setDate]          = useState(today);
  const [rows,          setRows]          = useState<EntryRow[]>([emptyRow()]);
  const [queue,     setQueue]     = useState<ProductionSession[]>([]);
  const [isOnline,  setIsOnline]  = useState(true);
  const [toast,     setToast]     = useState<{ type: 'ok'|'err'|'info'; msg: string } | null>(null);
  const [isSyncing, setIsSyncing] = useState(false);

  const syncingRef = useRef(false);

  const workers = useWorkers({ status: 'active', per_page: 200 });
  const units   = useProductionUnits(parseInt(lineId) || undefined);
  const batch   = useProductionBatch();
  const { pendingCount } = useSyncStatus();

  // ── Online / offline ───────────────────────────────────────────────────────

  useEffect(() => {
    const update = () => setIsOnline(navigator.onLine);
    update();
    window.addEventListener('online',  update);
    window.addEventListener('offline', update);
    return () => {
      window.removeEventListener('online',  update);
      window.removeEventListener('offline', update);
    };
  }, []);

  // ── Service worker registration ────────────────────────────────────────────

  useEffect(() => {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker
        .register('/sw.js')
        .catch(() => { /* SW unavailable — graceful degradation */ });
    }
  }, []);

  // ── Refresh local queue display ────────────────────────────────────────────

  const refreshQueue = useCallback(async () => {
    try {
      const items = await getQueue();
      setQueue(items);
    } catch { /* IndexedDB unavailable */ }
  }, []);

  useEffect(() => { refreshQueue(); }, [refreshQueue]);

  // ── Auto-sync when coming back online ─────────────────────────────────────

  useEffect(() => {
    const handleOnline = async () => {
      if (syncingRef.current) return;
      const items = await getQueue();
      if (!items.length) return;

      syncingRef.current = true;
      setIsSyncing(true);

      try {
        await syncQueue();
        await refreshQueue();
        showToast('ok', 'Queued entries synced successfully');
      } catch {
        showToast('err', 'Sync failed — will retry shortly');
      } finally {
        setIsSyncing(false);
        syncingRef.current = false;
      }

      // Signal Background Sync to the service worker
      if ('serviceWorker' in navigator && 'SyncManager' in window) {
        const reg = await navigator.serviceWorker.ready;
        await (reg as ServiceWorkerRegistration & {
          sync: { register(tag: string): Promise<void> };
        }).sync.register('production-sync').catch(() => {});
      }
    };

    window.addEventListener('online', handleOnline);
    return () => window.removeEventListener('online', handleOnline);
  }, [refreshQueue]);

  // ── Toast ──────────────────────────────────────────────────────────────────

  function showToast(type: 'ok'|'err'|'info', msg: string) {
    setToast({ type, msg });
    setTimeout(() => setToast(null), 3_500);
  }

  // ── Validation ────────────────────────────────────────────────────────────

  function validate(): boolean {
    const valid = rows.filter(r => r.worker_id && r.task && r.pairs_produced);
    if (!valid.length) {
      showToast('err', 'Fill in worker, task, and pairs for at least one row.');
      return false;
    }
    if (!lineId) {
      showToast('err', 'Enter a Line ID before submitting.');
      return false;
    }
    if (!productionUnitId) {
      showToast('err', 'Select a Production Unit before submitting.');
      return false;
    }
    return true;
  }

  function buildRecords() {
    return rows
      .filter(r => r.worker_id && r.task && r.pairs_produced)
      .map(r => ({
        worker_id:          parseInt(r.worker_id),
        line_id:            parseInt(lineId),
        production_unit_id: parseInt(productionUnitId),
        work_date:          date,
        shift,
        task:               r.task,
        pairs_produced:     parseInt(r.pairs_produced),
        source_tag:         'manual_supervisor' as const,
        supervisor_notes:   r.supervisor_notes || undefined,
      }));
  }

  // ── Submit: online → direct POST, offline → queue ─────────────────────────

  async function handleSubmit() {
    if (!validate()) return;

    const records = buildRecords();

    if (isOnline) {
      // Direct submission
      batch.mutate(
        { records },
        {
          onSuccess: (res) => {
            setRows([emptyRow()]);
            showToast('ok', `${res.data.created_count} record(s) submitted`);
          },
          onError: async (err) => {
            // API failed while online — save to queue as fallback
            const id = await saveToQueue({ records });
            await refreshQueue();
            showToast(
              'info',
              `Server error — saved to queue (id ${id}). ` +
              (err instanceof Error ? err.message : '')
            );
          },
        }
      );
    } else {
      // Offline — save to IndexedDB
      const id = await saveToQueue({ records });
      await refreshQueue();
      setRows([emptyRow()]);
      showToast('info', `${records.length} record(s) saved to queue (id ${id})`);

      // Register background sync with the service worker
      if ('serviceWorker' in navigator && 'SyncManager' in window) {
        const reg = await navigator.serviceWorker.ready;
        await (reg as ServiceWorkerRegistration & {
          sync: { register(tag: string): Promise<void> };
        }).sync.register('production-sync').catch(() => {});
      }
    }
  }

  // ── Manual queue sync ──────────────────────────────────────────────────────

  async function handleManualSync() {
    if (syncingRef.current) return;
    syncingRef.current = true;
    setIsSyncing(true);

    try {
      const result = await syncQueue();
      await refreshQueue();

      if (result.synced > 0 && result.failed === 0) {
        showToast('ok', `${result.synced} session(s) synced`);
      } else if (result.failed > 0) {
        showToast('err', `${result.synced} synced, ${result.failed} failed`);
      } else {
        showToast('info', 'Nothing to sync');
      }
    } catch {
      showToast('err', 'Sync failed');
    } finally {
      setIsSyncing(false);
      syncingRef.current = false;
    }
  }

  // ── Discard queue ──────────────────────────────────────────────────────────

  async function handleClearQueue() {
    await clearQueue();
    await refreshQueue();
    showToast('ok', 'Queue cleared');
  }

  // ── Remove single queued session ───────────────────────────────────────────

  async function handleRemoveSession(id: number) {
    await removeFromQueue(id);
    await refreshQueue();
  }

  // ── Row helpers ────────────────────────────────────────────────────────────

  function updateRow(id: string, field: keyof EntryRow, value: string) {
    setRows(prev => prev.map(r => r.id === id ? { ...r, [field]: value } : r));
  }

  function addRow() { setRows(prev => [...prev, emptyRow()]); }
  function removeRow(id: string) {
    setRows(prev => prev.length > 1 ? prev.filter(r => r.id !== id) : prev);
  }

  const workerList = workers.data?.data ?? [];

  // ── Render ─────────────────────────────────────────────────────────────────

  return (
    <div className="p-6 space-y-4 max-w-5xl mx-auto">

      {/* Offline / sync status banner */}
      <OfflineIndicator />

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Production Entry</h1>
          <p className="text-sm text-muted-foreground mt-0.5">Supervisor session entry</p>
        </div>
        <div className="flex items-center gap-2">
          {isOnline ? (
            <Badge className="bg-green-100 text-green-700 border-0 gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"/> Online
            </Badge>
          ) : (
            <Badge className="bg-amber-100 text-amber-700 border-0 gap-1.5">
              <WifiOff size={11}/> Offline — queue active
            </Badge>
          )}
          {(pendingCount > 0 || queue.length > 0) && (
            <Badge className="bg-brand-dark text-white border-0">
              {Math.max(pendingCount, queue.length)} queued
            </Badge>
          )}
        </div>
      </div>

      {/* Toast */}
      {toast && (
        <div className={`flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm
          ${toast.type === 'ok'   ? 'bg-green-50  border border-green-200  text-green-800'  : ''}
          ${toast.type === 'err'  ? 'bg-red-50    border border-red-200    text-red-800'    : ''}
          ${toast.type === 'info' ? 'bg-blue-50   border border-blue-200   text-blue-800'   : ''}`}
        >
          {toast.type === 'ok'  && <CheckCircle2  size={15} className="shrink-0"/>}
          {toast.type === 'err' && <AlertTriangle size={15} className="shrink-0"/>}
          {toast.type === 'info'&& <CloudUpload   size={15} className="shrink-0"/>}
          {toast.msg}
        </div>
      )}

      {/* Session controls */}
      <div className="bg-card rounded-xl border border-border p-5">
        <h2 className="font-semibold text-sm text-muted-foreground uppercase tracking-wide mb-4">
          Session
        </h2>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div className="space-y-1.5">
            <Label className="text-xs">Date</Label>
            <Input
              type="date"
              value={date}
              onChange={e => setDate(e.target.value)}
              className="h-9"
            />
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs">Shift</Label>
            <Select value={shift} onValueChange={v => setShift(v as typeof shift)}>
              <SelectTrigger className="h-9">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="morning">Morning</SelectItem>
                <SelectItem value="evening">Evening</SelectItem>
                <SelectItem value="night">Night</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs">Line ID</Label>
            <Input
              type="number"
              min={1}
              placeholder="Line #"
              value={lineId}
              onChange={e => setLineId(e.target.value)}
              className="h-9"
            />
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs">Production Unit <span className="text-destructive">*</span></Label>
            <Select
              value={productionUnitId}
              onValueChange={v => {
                setProductionUnitId(v);
                const unitList: ProductionUnit[] = (units.data as any)?.data ?? [];
                setSelectedUnit(unitList.find(u => String(u.id) === v) ?? null);
              }}
            >
              <SelectTrigger className="h-9">
                <SelectValue placeholder="Select unit…" />
              </SelectTrigger>
              <SelectContent>
                {units.isPending
                  ? <SelectItem value="_">Loading…</SelectItem>
                  : ((units.data as any)?.data ?? []).map((u: ProductionUnit) => (
                      <SelectItem key={u.id} value={String(u.id)}>
                        {u.name}
                      </SelectItem>
                    ))
                }
              </SelectContent>
            </Select>
          </div>
        </div>

        {/* Wage model context */}
        {selectedUnit && (
          <div className="mt-4 flex flex-wrap items-center gap-3 px-1 text-sm">
            <span className="text-muted-foreground text-xs font-medium">Wage Model:</span>
            <WageModelBadge model={selectedUnit.wage_model} />
            {selectedUnit.wage_model === 'daily_grade' && (
              <span className="text-xs text-muted-foreground">
                Pairs tracked for productivity only
              </span>
            )}
            {selectedUnit.wage_model === 'hybrid' && (
              <span className="text-xs text-muted-foreground">
                Standard: {selectedUnit.standard_output_day ?? '—'} pairs/day
                {' '}|{' '}
                Bonus: PKR {selectedUnit.bonus_rate_per_pair ?? '—'}/pair above standard
              </span>
            )}
          </div>
        )}
      </div>

      {/* Entry rows */}
      <div className="bg-card rounded-xl border border-border overflow-hidden">
        <div className="px-5 py-3 border-b border-border flex items-center justify-between">
          <h2 className="font-semibold text-sm text-foreground">Workers</h2>
          <Button variant="ghost" size="sm" onClick={addRow} className="h-7 gap-1.5 text-xs">
            <Plus size={13}/> Add row
          </Button>
        </div>

        <div className="divide-y divide-border">
          {/* Column headers */}
          <div className="grid grid-cols-12 gap-2 px-4 py-2 bg-muted/30 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
            <div className="col-span-4">Worker</div>
            <div className="col-span-3">Task</div>
            <div className="col-span-2">Pairs</div>
            <div className="col-span-2">Notes</div>
            <div className="col-span-1"></div>
          </div>

          {rows.map(row => (
            <div key={row.id} className="grid grid-cols-12 gap-2 px-4 py-2.5 items-center">
              <div className="col-span-4">
                <Select
                  value={row.worker_id}
                  onValueChange={v => updateRow(row.id, 'worker_id', v)}
                >
                  <SelectTrigger className="h-8 text-xs">
                    <SelectValue placeholder="Select worker…" />
                  </SelectTrigger>
                  <SelectContent>
                    {workers.isPending
                      ? <SelectItem value="_">Loading…</SelectItem>
                      : workerList.map(w => (
                          <SelectItem key={w.id} value={String(w.id)}>
                            {w.name} · {w.grade}
                          </SelectItem>
                        ))
                    }
                  </SelectContent>
                </Select>
              </div>

              <div className="col-span-3">
                <Input
                  placeholder="Stitching…"
                  value={row.task}
                  onChange={e => updateRow(row.id, 'task', e.target.value)}
                  className="h-8 text-xs"
                />
              </div>

              <div className="col-span-2">
                <Input
                  type="number" min={0}
                  placeholder="0"
                  value={row.pairs_produced}
                  onChange={e => updateRow(row.id, 'pairs_produced', e.target.value)}
                  className="h-8 text-xs"
                />
              </div>

              <div className="col-span-2">
                <Input
                  placeholder="Optional…"
                  value={row.supervisor_notes}
                  onChange={e => updateRow(row.id, 'supervisor_notes', e.target.value)}
                  className="h-8 text-xs"
                />
              </div>

              <div className="col-span-1 flex justify-end">
                <button
                  onClick={() => removeRow(row.id)}
                  className="text-muted-foreground hover:text-destructive transition-colors p-1"
                  disabled={rows.length === 1}
                >
                  <Trash2 size={13}/>
                </button>
              </div>
            </div>
          ))}
        </div>

        {/* Submit button — behaviour changes online vs offline */}
        <div className="px-5 py-3 border-t border-border flex items-center justify-between">
          <p className="text-xs text-muted-foreground">
            {isOnline
              ? 'Records will be submitted directly to the server'
              : 'You are offline — records will be saved and synced when reconnected'}
          </p>
          <Button
            onClick={handleSubmit}
            disabled={batch.isPending}
            className={`gap-2 ${
              isOnline
                ? 'bg-brand-dark hover:bg-brand-mid text-white'
                : 'bg-amber-600 hover:bg-amber-700 text-white'
            }`}
          >
            {batch.isPending ? (
              <><Loader2 size={14} className="animate-spin"/> Submitting…</>
            ) : isOnline ? (
              <><Send size={14}/> Submit</>
            ) : (
              <><CloudUpload size={14}/> Save Offline</>
            )}
          </Button>
        </div>
      </div>

      {/* Offline queue panel */}
      {queue.length > 0 && (
        <div className="bg-card rounded-xl border border-border overflow-hidden">
          <div className="px-5 py-3 border-b border-border flex items-center justify-between">
            <h2 className="font-semibold text-sm text-foreground flex items-center gap-2">
              Queued Sessions
              <Badge className="bg-brand-dark text-white border-0 text-xs">
                {queue.length}
              </Badge>
            </h2>
            <div className="flex gap-2">
              <Button
                variant="ghost" size="sm"
                onClick={handleClearQueue}
                className="h-7 text-xs text-muted-foreground hover:text-destructive"
              >
                <Trash2 size={12} className="mr-1"/> Clear all
              </Button>
              <Button
                size="sm"
                onClick={handleManualSync}
                disabled={isSyncing || !isOnline}
                className="h-7 text-xs bg-brand-dark hover:bg-brand-mid text-white gap-1.5"
              >
                {isSyncing
                  ? <><Loader2 size={12} className="animate-spin"/> Syncing…</>
                  : <><CloudUpload size={12}/> Sync {queue.length} session(s)</>
                }
              </Button>
            </div>
          </div>

          <div className="divide-y divide-border max-h-64 overflow-y-auto">
            {queue.map((session, i) => (
              <div
                key={session._localId ?? i}
                className="px-4 py-2.5 flex items-center gap-4 text-xs"
              >
                <span className="text-muted-foreground w-24 shrink-0">
                  {new Date(session.queued_at).toLocaleTimeString()}
                </span>
                <span className="font-medium text-foreground">
                  {session.records.length} record{session.records.length !== 1 ? 's' : ''}
                </span>
                <span className="text-muted-foreground">
                  {session.records[0]?.work_date} · {session.records[0]?.shift}
                </span>
                {session.retry_count > 0 && (
                  <Badge className="bg-red-100 text-red-700 border-0 text-xs">
                    {session.retry_count} retry{session.retry_count !== 1 ? 's' : ''}
                  </Badge>
                )}
                <button
                  onClick={() => session._localId != null && handleRemoveSession(session._localId)}
                  className="ml-auto text-muted-foreground hover:text-destructive transition-colors"
                  title="Remove session"
                >
                  <Trash2 size={12}/>
                </button>
              </div>
            ))}
          </div>

          {!isOnline && (
            <div className="px-5 py-3 border-t border-amber-200 bg-amber-50 flex items-center gap-2 text-xs text-amber-700">
              <WifiOff size={12}/>
              Sessions will sync automatically when you reconnect.
            </div>
          )}
        </div>
      )}
    </div>
  );
}
