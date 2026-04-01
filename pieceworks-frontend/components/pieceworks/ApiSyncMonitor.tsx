'use client';

import { useState }  from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { Button }    from '@/components/ui/button';
import { Badge }     from '@/components/ui/badge';
import { Skeleton }  from '@/components/ui/skeleton';
import { RefreshCw, X, CheckCircle2, AlertTriangle, XCircle, Loader2 } from 'lucide-react';

// ── Types ─────────────────────────────────────────────────────────────────────

type SyncHealth = 'healthy' | 'degraded' | 'error' | 'unknown';

interface SyncStatus {
  health:        SyncHealth;
  last_sync_at:  string | null;
  message:       string;
}

interface SyncEvent {
  id:         number;
  synced_at:  string;
  status:     'success' | 'partial' | 'failed';
  records:    number;
  message:    string;
}

interface SyncHistoryResponse {
  events: SyncEvent[];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function relativeTime(iso: string): string {
  const diff = (Date.now() - new Date(iso).getTime()) / 1000;
  if (diff < 60)   return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
}

const HEALTH_CONFIG: Record<SyncHealth, { dot: string; label: string; icon: React.ReactNode }> = {
  healthy:  { dot: 'bg-green-500', label: 'Synced',  icon: <CheckCircle2 size={12} className="text-green-600"/> },
  degraded: { dot: 'bg-amber-400', label: 'Delayed', icon: <AlertTriangle size={12} className="text-amber-500"/> },
  error:    { dot: 'bg-red-500',   label: 'Error',   icon: <XCircle size={12} className="text-red-500"/> },
  unknown:  { dot: 'bg-muted-foreground/40', label: 'Unknown', icon: null },
};

const EVENT_COLORS: Record<string, string> = {
  success: 'bg-green-100 text-green-700',
  partial: 'bg-amber-100 text-amber-700',
  failed:  'bg-red-100 text-red-700',
};

// ── Component ─────────────────────────────────────────────────────────────────

export function ApiSyncMonitor() {
  const [panelOpen, setPanelOpen] = useState(false);
  const queryClient = useQueryClient();

  const status = useQuery({
    queryKey: ['bata-sync-status'],
    queryFn:  () =>
      apiClient.get<{ data: SyncStatus }>('/integration/bata/status'),
    refetchInterval: 5 * 60 * 1000,   // poll every 5 minutes
    retry: false,                      // don't hammer on error
  });

  const history = useQuery({
    queryKey: ['bata-sync-history'],
    queryFn:  () =>
      apiClient.get<{ data: SyncHistoryResponse }>('/integration/bata/events?per_page=10'),
    enabled:  panelOpen,
    retry:    false,
  });

  const forceSync = useMutation({
    mutationFn: () =>
      apiClient.post<{ data: { queued: boolean } }>('/integration/bata/sync-now'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bata-sync-status'] });
      queryClient.invalidateQueries({ queryKey: ['bata-sync-history'] });
    },
  });

  const syncData = status.data?.data;
  const health   = syncData?.health ?? 'unknown';
  const cfg      = HEALTH_CONFIG[health];
  const events   = history.data?.data?.events ?? [];

  return (
    <>
      {/* Status pill — always visible */}
      <button
        onClick={() => setPanelOpen(true)}
        className="flex items-center gap-2 px-3 py-1.5 rounded-lg border border-border bg-card hover:bg-muted/60 transition-colors text-xs"
        title="Bata API sync status"
      >
        {status.isPending ? (
          <span className="w-2 h-2 rounded-full bg-muted-foreground/40 animate-pulse"/>
        ) : (
          <span className={`w-2 h-2 rounded-full ${cfg.dot} ${health === 'healthy' ? '' : 'animate-pulse'}`}/>
        )}
        <span className="text-muted-foreground font-medium">Bata Sync</span>
        {syncData?.last_sync_at && (
          <span className="text-muted-foreground/60">{relativeTime(syncData.last_sync_at)}</span>
        )}
      </button>

      {/* Slide panel */}
      {panelOpen && (
        <div className="fixed inset-0 z-50 flex justify-end" onClick={() => setPanelOpen(false)}>
          <div
            className="w-80 h-full bg-card border-l border-border shadow-xl flex flex-col"
            onClick={e => e.stopPropagation()}
          >
            {/* Panel header */}
            <div className="flex items-center justify-between px-4 py-3 border-b border-border">
              <div className="flex items-center gap-2">
                {cfg.icon}
                <span className="font-semibold text-sm text-foreground">Bata API Sync</span>
                <Badge className={`text-xs border-0 ${
                  health === 'healthy' ? 'bg-green-100 text-green-700' :
                  health === 'degraded' ? 'bg-amber-100 text-amber-700' :
                  health === 'error' ? 'bg-red-100 text-red-700' :
                  'bg-muted text-muted-foreground'
                }`}>
                  {cfg.label}
                </Badge>
              </div>
              <button onClick={() => setPanelOpen(false)} className="text-muted-foreground hover:text-foreground">
                <X size={16}/>
              </button>
            </div>

            {/* Status detail */}
            <div className="px-4 py-3 border-b border-border text-xs text-muted-foreground space-y-1">
              {syncData?.message && <p>{syncData.message}</p>}
              {syncData?.last_sync_at && (
                <p>Last sync: {new Date(syncData.last_sync_at).toLocaleString()}</p>
              )}
            </div>

            {/* Force sync button */}
            <div className="px-4 py-3 border-b border-border">
              <Button
                size="sm"
                onClick={() => forceSync.mutate()}
                disabled={forceSync.isPending}
                className="w-full gap-2 bg-brand-dark hover:bg-brand-mid text-white"
              >
                {forceSync.isPending
                  ? <Loader2 size={13} className="animate-spin"/>
                  : <RefreshCw size={13}/>
                }
                {forceSync.isPending ? 'Syncing…' : 'Force Sync Now'}
              </Button>
              {forceSync.isSuccess && (
                <p className="text-xs text-green-600 mt-2 text-center">Sync queued successfully.</p>
              )}
              {forceSync.isError && (
                <p className="text-xs text-red-500 mt-2 text-center">Sync failed. Try again.</p>
              )}
            </div>

            {/* Event history */}
            <div className="flex-1 overflow-y-auto">
              <div className="px-4 py-2 border-b border-border">
                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  Last 10 Sync Events
                </p>
              </div>
              {history.isPending ? (
                <div className="p-4 space-y-2">
                  {Array.from({ length: 5 }).map((_, i) => (
                    <Skeleton key={i} className="h-12 rounded-lg"/>
                  ))}
                </div>
              ) : history.isError ? (
                <div className="p-4 text-xs text-muted-foreground">
                  Could not load sync history.
                </div>
              ) : events.length === 0 ? (
                <div className="p-4 text-xs text-muted-foreground text-center">
                  No sync events recorded yet.
                </div>
              ) : (
                <ul className="divide-y divide-border">
                  {events.map(ev => (
                    <li key={ev.id} className="px-4 py-3 space-y-1">
                      <div className="flex items-center justify-between">
                        <Badge className={`text-xs border-0 ${EVENT_COLORS[ev.status] ?? 'bg-muted text-muted-foreground'}`}>
                          {ev.status}
                        </Badge>
                        <span className="text-xs text-muted-foreground">{relativeTime(ev.synced_at)}</span>
                      </div>
                      <p className="text-xs text-muted-foreground">{ev.message}</p>
                      <p className="text-xs font-mono text-foreground">{ev.records} records</p>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
