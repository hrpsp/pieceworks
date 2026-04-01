'use client';

import { useState }     from 'react';
import {
  useCurrentPayroll, useLockPayroll, useReleasePayment,
  type WeeklyPayrollRun,
} from '@/hooks/usePayroll';
import { formatPKR }    from '@/lib/formatters';
import { apiClient }    from '@/lib/api-client';
import { Button }       from '@/components/ui/button';
import { Badge }        from '@/components/ui/badge';
import { Skeleton }     from '@/components/ui/skeleton';
import {
  Dialog, DialogContent, DialogHeader,
  DialogTitle, DialogFooter, DialogDescription,
} from '@/components/ui/dialog';
import {
  Lock, Unlock, Loader2, FileText, MessageCircle,
  AlertTriangle, CheckCircle2,
} from 'lucide-react';
import { PermissionGate, PERMISSIONS } from '@/lib/permissions';

// ── Status badge ──────────────────────────────────────────────────────────────

function RunBadge({ status }: { status?: string }) {
  const map: Record<string, string> = {
    open:       'bg-blue-100 text-blue-700',
    processing: 'bg-amber-100 text-amber-700',
    locked:     'bg-purple-100 text-purple-700',
    paid:       'bg-green-100 text-green-700',
    reversed:   'bg-red-100 text-red-700',
  };
  if (!status) return null;
  return (
    <span className={`text-xs font-semibold px-2.5 py-1 rounded-full capitalize ${map[status] ?? 'bg-muted text-muted-foreground'}`}>
      {status}
    </span>
  );
}

// ── Confirmation dialogs ──────────────────────────────────────────────────────

function LockConfirmDialog({
  weekRef,
  onClose,
}: {
  weekRef: string;
  onClose: () => void;
}) {
  const lock = useLockPayroll();

  function confirm() {
    lock.mutate({ weekRef }, { onSuccess: onClose });
  }

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Lock size={16} className="text-purple-600"/>
            Lock Payroll Run
          </DialogTitle>
          <DialogDescription>
            Lock <span className="font-mono font-semibold">{weekRef}</span>? This will prevent further edits
            to production records and freeze all payroll amounts.
          </DialogDescription>
        </DialogHeader>
        <div className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-800">
          <AlertTriangle size={13} className="mt-0.5 shrink-0 text-amber-600"/>
          All unresolved exceptions must be cleared before the run can be locked.
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={lock.isPending}>Cancel</Button>
          <Button
            onClick={confirm}
            disabled={lock.isPending}
            className="bg-purple-600 hover:bg-purple-700 text-white"
          >
            {lock.isPending && <Loader2 size={13} className="animate-spin mr-1.5"/>}
            Lock Run
          </Button>
        </DialogFooter>
        {lock.isError && (
          <p className="text-xs text-red-500 text-center -mt-2">
            {(lock.error as Error)?.message ?? 'Lock failed. Check unresolved exceptions.'}
          </p>
        )}
      </DialogContent>
    </Dialog>
  );
}

function ReleaseConfirmDialog({
  weekRef,
  totalNet,
  onClose,
}: {
  weekRef:  string;
  totalNet: string;
  onClose:  () => void;
}) {
  const release = useReleasePayment();

  function confirm() {
    release.mutate({ weekRef }, { onSuccess: onClose });
  }

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Unlock size={16} className="text-green-600"/>
            Release Payment
          </DialogTitle>
          <DialogDescription>
            Release <span className="font-mono font-semibold">{weekRef}</span> for payment disbursement?
          </DialogDescription>
        </DialogHeader>
        <div className="bg-green-50 border border-green-200 rounded-lg px-4 py-3 text-center">
          <p className="text-xs text-green-700 mb-1">Total disbursement</p>
          <p className="text-2xl font-bold text-green-800">{formatPKR(Number(totalNet))}</p>
        </div>
        <div className="flex items-start gap-2 bg-blue-50 border border-blue-200 rounded-lg px-3 py-2 text-xs text-blue-800">
          <CheckCircle2 size={13} className="mt-0.5 shrink-0 text-blue-600"/>
          Worker payment statuses will be set to <strong>processing</strong>. This cannot be undone without a reversal.
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={release.isPending}>Cancel</Button>
          <Button
            onClick={confirm}
            disabled={release.isPending}
            className="bg-green-700 hover:bg-green-800 text-white"
          >
            {release.isPending && <Loader2 size={13} className="animate-spin mr-1.5"/>}
            Release Payment
          </Button>
        </DialogFooter>
        {release.isError && (
          <p className="text-xs text-red-500 text-center -mt-2">
            {(release.error as Error)?.message ?? 'Release failed.'}
          </p>
        )}
      </DialogContent>
    </Dialog>
  );
}

// ── Statement helpers ─────────────────────────────────────────────────────────

async function generateStatements(weekRef: string): Promise<void> {
  await apiClient.post(`/payroll/${weekRef}/statements/generate`);
}

async function sendWhatsApp(weekRef: string): Promise<void> {
  await apiClient.post(`/payroll/${weekRef}/statements/send-whatsapp`);
}

// ── Main component ────────────────────────────────────────────────────────────

export function WeeklyPayrollPanel() {
  const payroll = useCurrentPayroll({
    refetchInterval: 60_000,  // auto-refresh every 60 seconds
  });

  const [confirmLock,    setConfirmLock]    = useState(false);
  const [confirmRelease, setConfirmRelease] = useState(false);
  const [genLoading,     setGenLoading]     = useState(false);
  const [genDone,        setGenDone]        = useState(false);
  const [waLoading,      setWaLoading]      = useState(false);
  const [waDone,         setWaDone]         = useState(false);

  const run     = payroll.data?.data?.run;
  const stats   = payroll.data?.data?.stats;
  const weekRef = payroll.data?.data?.week_ref ?? '';

  const unresolvedCount = stats?.unresolved_exception_count ?? 0;

  async function handleGenerate() {
    setGenLoading(true); setGenDone(false);
    try {
      await generateStatements(weekRef);
      setGenDone(true);
      setTimeout(() => setGenDone(false), 4000);
    } finally {
      setGenLoading(false);
    }
  }

  async function handleWhatsApp() {
    setWaLoading(true); setWaDone(false);
    try {
      await sendWhatsApp(weekRef);
      setWaDone(true);
      setTimeout(() => setWaDone(false), 4000);
    } finally {
      setWaLoading(false);
    }
  }

  if (payroll.isPending) {
    return (
      <div className="bg-card border border-border rounded-xl p-5 space-y-3">
        <Skeleton className="h-5 w-40"/>
        <div className="flex gap-3">
          <Skeleton className="h-9 w-24"/>
          <Skeleton className="h-9 w-24"/>
        </div>
      </div>
    );
  }

  if (!run) {
    return (
      <div className="bg-card border border-border rounded-xl p-5 text-sm text-muted-foreground text-center">
        No payroll run for {weekRef || 'this week'}.
      </div>
    );
  }

  return (
    <>
      <div className="bg-card border border-border rounded-xl p-5 space-y-4">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span className="font-semibold text-foreground text-sm font-mono">{weekRef}</span>
            <RunBadge status={run.status}/>
            {unresolvedCount > 0 && (
              <Badge className="bg-red-100 text-red-700 border-0 text-xs gap-1">
                <AlertTriangle size={10}/>
                {unresolvedCount} unresolved
              </Badge>
            )}
          </div>
          {stats && (
            <span className="text-xs text-muted-foreground">
              {formatPKR(stats.total_net)} net
            </span>
          )}
        </div>

        {/* Action buttons */}
        <div className="flex flex-wrap items-center gap-2">

          {/* Lock */}
          {run.status === 'open' && (
            <PermissionGate permission={PERMISSIONS.PAYROLL_LOCK}>
              <Button
                size="sm"
                onClick={() => setConfirmLock(true)}
                disabled={unresolvedCount > 0}
                className="gap-2 bg-brand-dark hover:bg-brand-mid text-white"
              >
                <Lock size={13}/>
                Lock Run
              </Button>
            </PermissionGate>
          )}

          {/* Release */}
          {run.status === 'locked' && (
            <PermissionGate permission={PERMISSIONS.PAYROLL_RELEASE}>
              <Button
                size="sm"
                onClick={() => setConfirmRelease(true)}
                className="gap-2 bg-green-700 hover:bg-green-800 text-white"
              >
                <Unlock size={13}/>
                Release Payment
              </Button>
            </PermissionGate>
          )}

          {/* Generate statements */}
          {(run.status === 'locked' || run.status === 'paid') && (
            <Button
              variant="outline"
              size="sm"
              onClick={handleGenerate}
              disabled={genLoading}
              className="gap-2 border-brand-dark/30 text-brand-dark"
            >
              {genLoading
                ? <Loader2 size={13} className="animate-spin"/>
                : genDone
                  ? <CheckCircle2 size={13} className="text-green-600"/>
                  : <FileText size={13}/>
              }
              {genDone ? 'Generated!' : 'Generate Statements'}
            </Button>
          )}

          {/* WhatsApp send */}
          {(run.status === 'locked' || run.status === 'paid') && (
            <Button
              variant="outline"
              size="sm"
              onClick={handleWhatsApp}
              disabled={waLoading}
              className="gap-2 border-green-600/40 text-green-700 hover:bg-green-50"
            >
              {waLoading
                ? <Loader2 size={13} className="animate-spin"/>
                : waDone
                  ? <CheckCircle2 size={13} className="text-green-600"/>
                  : <MessageCircle size={13}/>
              }
              {waDone ? 'Sent!' : 'Send via WhatsApp'}
            </Button>
          )}
        </div>

        {/* Mini stats */}
        {stats && (
          <div className="grid grid-cols-3 gap-3 pt-1 border-t border-border">
            <MiniStat label="Workers"    value={stats.worker_count} />
            <MiniStat label="Gross"      value={formatPKR(stats.total_gross)} />
            <MiniStat label="Net"        value={formatPKR(stats.total_net)} highlight />
          </div>
        )}
      </div>

      {/* Confirmation modals */}
      {confirmLock && (
        <LockConfirmDialog weekRef={weekRef} onClose={() => setConfirmLock(false)}/>
      )}
      {confirmRelease && run && (
        <ReleaseConfirmDialog
          weekRef={weekRef}
          totalNet={run.total_net}
          onClose={() => setConfirmRelease(false)}
        />
      )}
    </>
  );
}

function MiniStat({ label, value, highlight }: { label: string; value: string | number; highlight?: boolean }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={`text-sm font-bold mt-0.5 ${highlight ? 'text-brand-dark' : 'text-foreground'}`}>{value}</p>
    </div>
  );
}
