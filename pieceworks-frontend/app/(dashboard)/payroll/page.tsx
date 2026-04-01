'use client';

import { useState }              from 'react';
import {
  useCurrentPayroll, useLockPayroll, useReleasePayment,
  useResolveException, payrollKeys, type PayrollException,
} from '@/hooks/usePayroll';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient }             from '@/lib/api-client';
import { Button }                from '@/components/ui/button';
import { Skeleton }              from '@/components/ui/skeleton';
import { Badge }                 from '@/components/ui/badge';
import {
  Dialog, DialogContent, DialogHeader,
  DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import { Label }    from '@/components/ui/label';
import { Input }    from '@/components/ui/input';
import {
  AlertTriangle, Lock, Unlock, CheckCircle2, Loader2,
  ChevronLeft, ChevronRight, Calculator,
} from 'lucide-react';
import { PermissionGate, PERMISSIONS } from '@/lib/permissions';

// ── Types ─────────────────────────────────────────────────────────────────────

interface WorkerPayroll {
  id: number; worker_id: number; gross_earnings: string; ot_premium: string;
  shift_allowance: string; min_wage_supplement: string; total_gross: string;
  advance_deductions: string; rejection_deductions: string; loan_deductions: string;
  net_pay: string; payment_status: string;
  worker?: { id: number; name: string; grade: string };
}

// ── Status badge helper ───────────────────────────────────────────────────────

function RunBadge({ status }: { status?: string }) {
  const map: Record<string, string> = {
    open:       'bg-blue-100 text-blue-700',
    processing: 'bg-amber-100 text-amber-700',
    locked:     'bg-purple-100 text-purple-700',
    paid:       'bg-green-100 text-green-700',
  };
  if (!status) return null;
  return (
    <span className={`text-xs font-semibold px-2.5 py-1 rounded-full capitalize ${map[status] ?? 'bg-muted text-muted-foreground'}`}>
      {status}
    </span>
  );
}

function ExcTypeBadge({ type }: { type: string }) {
  const map: Record<string, string> = {
    min_wage_shortfall: 'bg-orange-100 text-orange-700',
    missing_rate:       'bg-red-100 text-red-700',
    negative_net_carry: 'bg-purple-100 text-purple-700',
    disputed_records:   'bg-yellow-100 text-yellow-700',
    manual:             'bg-muted text-muted-foreground',
  };
  return (
    <span className={`text-xs px-2 py-0.5 rounded-full ${map[type] ?? 'bg-muted text-muted-foreground'}`}>
      {type.replace(/_/g, ' ')}
    </span>
  );
}

// ── Resolve modal ─────────────────────────────────────────────────────────────

function ResolveModal({
  exception, onClose,
}: {
  exception: PayrollException; onClose: () => void;
}) {
  const [note, setNote] = useState('');
  const resolve = useResolveException();

  function submit() {
    if (note.trim().length < 10) return;
    resolve.mutate(
      { id: exception.id, weekRef: '', resolution_note: note },
      { onSuccess: onClose }
    );
  }

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Resolve Exception</DialogTitle>
        </DialogHeader>
        <div className="space-y-4 py-2">
          <div className="bg-muted/50 rounded-lg p-3 text-sm">
            <p className="font-medium text-foreground">{exception.description}</p>
            {exception.amount && (
              <p className="text-muted-foreground mt-1">Amount: ₨ {Number(exception.amount).toLocaleString()}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs">Resolution Note <span className="text-muted-foreground">(min 10 chars)</span></Label>
            <Input
              value={note}
              onChange={e => setNote(e.target.value)}
              placeholder="Describe how this exception was resolved…"
              className="text-sm"
            />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button
            onClick={submit}
            disabled={note.trim().length < 10 || resolve.isPending}
            className="bg-brand-dark hover:bg-brand-mid text-white"
          >
            {resolve.isPending && <Loader2 size={13} className="animate-spin mr-1.5"/>}
            Resolve
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Calculate modal ───────────────────────────────────────────────────────────

function CalculateModal({ onClose }: { onClose: () => void }) {
  const [weekRef, setWeekRef] = useState(
    new Date().toISOString().slice(0,4) + '-W' +
    String(getISOWeek(new Date())).padStart(2,'0')
  );
  const [loading, setLoading] = useState(false);
  const [error, setError]     = useState('');
  const queryClient           = useQueryClient();

  async function run() {
    setLoading(true); setError('');
    try {
      await apiClient.post('/payroll/calculate', { week_ref: weekRef });
      queryClient.invalidateQueries({ queryKey: payrollKeys.all });
      onClose();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed');
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Calculate Payroll</DialogTitle>
        </DialogHeader>
        <div className="space-y-3 py-2">
          <div className="space-y-1.5">
            <Label className="text-xs">Week Reference</Label>
            <Input value={weekRef} onChange={e => setWeekRef(e.target.value)} placeholder="2025-W12" className="font-mono text-sm"/>
          </div>
          {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={run} disabled={loading} className="bg-brand-dark text-white hover:bg-brand-mid">
            {loading && <Loader2 size={13} className="animate-spin mr-1.5"/>} Calculate
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function getISOWeek(d: Date): number {
  const jan4 = new Date(d.getFullYear(), 0, 4);
  return Math.ceil(((d.getTime() - jan4.getTime()) / 86400000 + jan4.getDay() + 1) / 7);
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function PayrollPage() {
  const payroll      = useCurrentPayroll();
  const lock         = useLockPayroll();
  const release      = useReleasePayment();
  const [resolving, setResolving] = useState<PayrollException | null>(null);
  const [calcOpen,  setCalcOpen]  = useState(false);
  const [workerPage, setWorkerPage] = useState(1);

  const run     = payroll.data?.data?.run;
  const weekRef = payroll.data?.data?.week_ref ?? '';
  const stats   = payroll.data?.data?.stats;

  const exceptions = useQuery({
    queryKey: payrollKeys.exceptions(weekRef),
    queryFn: () => apiClient.get<{
      data: { exceptions: PayrollException[]; summary: { unresolved: number } };
    }>(`/payroll/${weekRef}/exceptions`),
    enabled: !!weekRef && !!run,
  });

  const workers = useQuery({
    queryKey: [...payrollKeys.workers(weekRef), workerPage],
    queryFn: () => apiClient.get<{
      data: WorkerPayroll[];
      meta: { total: number; last_page: number; per_page: number };
    }>(`/payroll/${weekRef}/workers?page=${workerPage}&per_page=15`),
    enabled: !!weekRef && !!run,
  });

  const exList    = exceptions.data?.data?.exceptions ?? [];
  const unresolved = exList.filter(e => !e.resolved_at);
  const workerList = workers.data?.data ?? [];
  const wMeta      = workers.data?.meta;

  function handleLock() {
    if (weekRef) lock.mutate({ weekRef });
  }
  function handleRelease() {
    if (weekRef) release.mutate({ weekRef });
  }

  return (
    <div className="p-6 space-y-6 max-w-7xl mx-auto">

      {/* Modals */}
      {resolving && <ResolveModal exception={resolving} onClose={() => setResolving(null)}/>}
      {calcOpen  && <CalculateModal onClose={() => setCalcOpen(false)}/>}

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Payroll</h1>
          <p className="text-sm text-muted-foreground mt-0.5">{weekRef || 'Current week'}</p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            onClick={() => setCalcOpen(true)}
            className="gap-2 border-brand-dark text-brand-dark"
          >
            <Calculator size={14}/> Calculate
          </Button>
          {run?.status === 'open' && (
            <PermissionGate permission={PERMISSIONS.PAYROLL_LOCK}>
              <Button
                onClick={handleLock}
                disabled={lock.isPending || (unresolved.length > 0)}
                className="gap-2 bg-brand-dark hover:bg-brand-mid text-white"
              >
                {lock.isPending ? <Loader2 size={14} className="animate-spin"/> : <Lock size={14}/>}
                Lock Run
              </Button>
            </PermissionGate>
          )}
          {run?.status === 'locked' && (
            <PermissionGate permission={PERMISSIONS.PAYROLL_RELEASE}>
              <Button
                onClick={handleRelease}
                disabled={release.isPending}
                className="gap-2 bg-green-700 hover:bg-green-800 text-white"
              >
                {release.isPending ? <Loader2 size={14} className="animate-spin"/> : <Unlock size={14}/>}
                Release Payment
              </Button>
            </PermissionGate>
          )}
        </div>
      </div>

      {payroll.isPending ? (
        <div className="grid grid-cols-4 gap-4">
          {Array.from({length:4}).map((_,i) => <Skeleton key={i} className="h-24 rounded-xl"/>)}
        </div>
      ) : !run ? (
        <div className="bg-card rounded-xl border border-border p-12 text-center space-y-3">
          <Calculator size={32} className="mx-auto text-muted-foreground/40"/>
          <p className="text-muted-foreground">No payroll run for this week yet.</p>
          <Button onClick={() => setCalcOpen(true)} className="bg-brand-dark hover:bg-brand-mid text-white gap-2">
            <Calculator size={14}/> Calculate Now
          </Button>
        </div>
      ) : (
        <>
          {/* Stats */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <SCard label="Status"><RunBadge status={run.status}/></SCard>
            <SCard label="Workers" value={stats?.worker_count ?? 0}/>
            <SCard label="Total Net" value={`₨ ${Number(run.total_net).toLocaleString()}`}/>
            <SCard label="Exceptions" value={stats?.exception_count ?? 0} accent={!!stats?.unresolved_exception_count}/>
          </div>

          {/* Lock gate warning */}
          {run.status === 'open' && unresolved.length > 0 && (
            <div className="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-start gap-3">
              <AlertTriangle size={16} className="text-amber-600 mt-0.5 shrink-0"/>
              <div>
                <p className="text-sm font-medium text-amber-800">
                  {unresolved.length} unresolved exception{unresolved.length !== 1 ? 's' : ''} — cannot lock
                </p>
                <p className="text-xs text-amber-700 mt-0.5">
                  Resolve all exceptions below before locking the payroll run.
                </p>
              </div>
            </div>
          )}

          {/* Exceptions */}
          {exList.length > 0 && (
            <div className="bg-card rounded-xl border border-border overflow-hidden">
              <div className="px-5 py-3 border-b border-border flex items-center justify-between">
                <h2 className="font-semibold text-sm text-foreground flex items-center gap-2">
                  Exceptions
                  {unresolved.length > 0 && (
                    <Badge className="bg-amber-100 text-amber-700 border-0">{unresolved.length} open</Badge>
                  )}
                </h2>
              </div>
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/40">
                    {['Worker', 'Type', 'Description', 'Amount', 'Status', ''].map(h => (
                      <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {exceptions.isPending
                    ? Array.from({length:3}).map((_,i) => (
                        <tr key={i} className="border-b border-border">
                          {Array.from({length:6}).map((_,j) => <td key={j} className="px-4 py-3"><Skeleton className="h-3.5 w-20"/></td>)}
                        </tr>
                      ))
                    : exList.map(ex => (
                        <tr key={ex.id} className="border-b border-border last:border-0 hover:bg-muted/20">
                          <td className="px-4 py-3 font-medium text-xs">{ex.worker?.name ?? `#${ex.worker_id}`}</td>
                          <td className="px-4 py-3"><ExcTypeBadge type={ex.exception_type}/></td>
                          <td className="px-4 py-3 text-muted-foreground text-xs max-w-xs truncate">{ex.description}</td>
                          <td className="px-4 py-3 text-xs">{ex.amount ? `₨ ${Number(ex.amount).toLocaleString()}` : '—'}</td>
                          <td className="px-4 py-3">
                            {ex.resolved_at
                              ? <span className="flex items-center gap-1 text-xs text-green-700"><CheckCircle2 size={12}/>Resolved</span>
                              : <span className="text-xs text-amber-600 font-medium">Open</span>}
                          </td>
                          <td className="px-4 py-3">
                            {!ex.resolved_at && run.status !== 'locked' && run.status !== 'paid' && (
                              <Button
                                size="sm" variant="outline"
                                onClick={() => setResolving(ex)}
                                className="h-7 text-xs border-brand-dark text-brand-dark hover:bg-brand-dark hover:text-white"
                              >
                                Resolve
                              </Button>
                            )}
                          </td>
                        </tr>
                      ))
                  }
                </tbody>
              </table>
            </div>
          )}

          {/* Worker payroll table */}
          <div className="bg-card rounded-xl border border-border overflow-hidden">
            <div className="px-5 py-3 border-b border-border">
              <h2 className="font-semibold text-sm text-foreground">Worker Payroll Lines</h2>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/40">
                    {['Worker', 'Gross', 'OT', 'Allowance', 'Min Wage+', 'Deductions', 'Net Pay', 'Status'].map(h => (
                      <th key={h} className="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground whitespace-nowrap">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {workers.isPending
                    ? Array.from({length:5}).map((_,i) => (
                        <tr key={i} className="border-b border-border">
                          {Array.from({length:8}).map((_,j) => <td key={j} className="px-3 py-3"><Skeleton className="h-3.5 w-16"/></td>)}
                        </tr>
                      ))
                    : workerList.length === 0
                    ? <tr><td colSpan={8} className="px-4 py-8 text-center text-muted-foreground text-sm">No worker payroll records</td></tr>
                    : workerList.map(w => {
                        const deductions = Number(w.advance_deductions) + Number(w.rejection_deductions) + Number(w.loan_deductions);
                        return (
                          <tr key={w.id} className="border-b border-border last:border-0 hover:bg-muted/20 text-xs">
                            <td className="px-3 py-2.5 font-medium">{w.worker?.name ?? `#${w.worker_id}`}</td>
                            <td className="px-3 py-2.5">₨ {Number(w.gross_earnings).toLocaleString()}</td>
                            <td className="px-3 py-2.5 text-green-700">+{Number(w.ot_premium).toLocaleString()}</td>
                            <td className="px-3 py-2.5 text-green-700">+{Number(w.shift_allowance).toLocaleString()}</td>
                            <td className="px-3 py-2.5 text-blue-700">+{Number(w.min_wage_supplement).toLocaleString()}</td>
                            <td className="px-3 py-2.5 text-red-600">-{deductions.toLocaleString()}</td>
                            <td className="px-3 py-2.5 font-semibold text-foreground">₨ {Number(w.net_pay).toLocaleString()}</td>
                            <td className="px-3 py-2.5">
                              <span className={`px-1.5 py-0.5 rounded capitalize ${
                                w.payment_status === 'paid' ? 'bg-green-100 text-green-700' :
                                w.payment_status === 'processing' ? 'bg-blue-100 text-blue-700' :
                                'bg-muted text-muted-foreground'}`}>
                                {w.payment_status}
                              </span>
                            </td>
                          </tr>
                        );
                      })
                  }
                </tbody>
              </table>
            </div>
            {wMeta && wMeta.last_page > 1 && (
              <div className="px-5 py-3 border-t border-border flex items-center justify-between text-xs">
                <span className="text-muted-foreground">{wMeta.total} workers</span>
                <div className="flex items-center gap-2">
                  <button onClick={() => setWorkerPage(p => p-1)} disabled={workerPage<=1}
                    className="p-1 rounded hover:bg-muted disabled:opacity-40"><ChevronLeft size={14}/></button>
                  <span>{workerPage} / {wMeta.last_page}</span>
                  <button onClick={() => setWorkerPage(p => p+1)} disabled={workerPage>=wMeta.last_page}
                    className="p-1 rounded hover:bg-muted disabled:opacity-40"><ChevronRight size={14}/></button>
                </div>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}

function SCard({ label, value, accent, children }: { label: string; value?: string | number; accent?: boolean; children?: React.ReactNode }) {
  return (
    <div className="bg-card rounded-xl border border-border p-4">
      <p className="text-xs text-muted-foreground font-medium uppercase tracking-wide">{label}</p>
      {children ?? (
        <p className={`text-2xl font-bold mt-1 ${accent ? 'text-amber-600' : 'text-foreground'}`}>{value}</p>
      )}
    </div>
  );
}
