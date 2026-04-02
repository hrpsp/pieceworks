'use client';

import { useState }     from 'react';
import {
  useWorker,
  useWorkerProduction,
  useWorkerStatement,
  useWorkerAdvances,
  useWorkerShiftAdjustments,
  useWorkerLoans,
  useWorkerCompliance,
} from '@/hooks/useWorkers';
import { formatPKR }    from '@/lib/formatters';
import { apiClient }    from '@/lib/api-client';
import { Badge }        from '@/components/ui/badge';
import { Skeleton }     from '@/components/ui/skeleton';
import { Separator }    from '@/components/ui/separator';
import { Button }       from '@/components/ui/button';
import {
  Tabs, TabsContent, TabsList, TabsTrigger,
} from '@/components/ui/tabs';
import {
  ChevronLeft, User, Banknote, Phone, Calendar,
  CheckCircle2, Clock, XCircle, FileText, MessageCircle, Loader2,
  CreditCard, ShieldCheck,
} from 'lucide-react';
import Link from 'next/link';

// ── Helpers ───────────────────────────────────────────────────────────────────

function currentWeekRef() {
  const d = new Date();
  const thursday = new Date(d);
  const day = d.getDay() || 7;
  thursday.setDate(d.getDate() + (4 - day));
  const jan4    = new Date(thursday.getFullYear(), 0, 4);
  const jan4day = jan4.getDay() || 7;
  const week1Mon = new Date(jan4);
  week1Mon.setDate(jan4.getDate() - jan4day + 1);
  const week = Math.floor((thursday.getTime() - week1Mon.getTime()) / (7 * 86400000)) + 1;
  return `${thursday.getFullYear()}-W${String(week).padStart(2, '0')}`;
}

const STATUS_ICON: Record<string, React.ReactNode> = {
  validated: <CheckCircle2 size={13} className="text-green-600"/>,
  pending:   <Clock        size={13} className="text-amber-500"/>,
  rejected:  <XCircle      size={13} className="text-red-500"  />,
  disputed:  <XCircle      size={13} className="text-orange-500"/>,
};

// ── Page ──────────────────────────────────────────────────────────────────────

export default function WorkerDetailPage({ params }: { params: { id: string } }) {
  const workerId = parseInt(params.id, 10);
  const [weekRef, setWeekRef] = useState(currentWeekRef());

  const { data: workerRes, isPending } = useWorker(workerId);
  const worker = workerRes?.data;

  const production = useWorkerProduction(workerId, weekRef);
  const statement  = useWorkerStatement(workerId, weekRef);
  const advances   = useWorkerAdvances(workerId);
  const shifts     = useWorkerShiftAdjustments(workerId);
  const loans      = useWorkerLoans(workerId);
  const compliance = useWorkerCompliance(workerId);

  // Statement actions
  const [genLoading, setGenLoading] = useState(false);
  const [genDone,    setGenDone]    = useState(false);
  const [waLoading,  setWaLoading]  = useState(false);
  const [waDone,     setWaDone]     = useState(false);

  async function handleGenerate() {
    setGenLoading(true); setGenDone(false);
    try {
      await apiClient.post(`/workers/${workerId}/statement/${weekRef}/generate`);
      statement.refetch();
      setGenDone(true);
      setTimeout(() => setGenDone(false), 4000);
    } finally {
      setGenLoading(false);
    }
  }

  async function handleWhatsApp() {
    setWaLoading(true); setWaDone(false);
    try {
      await apiClient.post(`/workers/${workerId}/statement/${weekRef}/send-whatsapp`);
      setWaDone(true);
      setTimeout(() => setWaDone(false), 4000);
    } finally {
      setWaLoading(false);
    }
  }

  // Loading / not found
  if (isPending) {
    return (
      <div className="p-6 space-y-4">
        <Skeleton className="h-8 w-48"/>
        <div className="grid grid-cols-3 gap-4">
          {[1, 2, 3].map(i => <Skeleton key={i} className="h-28 rounded-xl"/>)}
        </div>
        <Skeleton className="h-64 rounded-xl"/>
      </div>
    );
  }

  if (!worker) {
    return (
      <div className="p-6">
        <p className="text-muted-foreground">Worker not found.</p>
        <Link href="/workers" className="text-brand-dark underline text-sm mt-2 inline-block">← Back</Link>
      </div>
    );
  }

  const statusColor =
    worker.status === 'active'   ? 'bg-green-100 text-green-700' :
    worker.status === 'inactive' ? 'bg-amber-100 text-amber-700' :
    'bg-red-100 text-red-700';

  const stmt        = statement.data?.data;
  const prodRows    = production.data?.data ?? [];
  const advRows     = advances.data?.data   ?? [];
  const shiftRows   = shifts.data?.data     ?? [];
  const loanRows    = loans.data?.data      ?? [];
  const comp        = compliance.data?.data ?? null;

  return (
    <div className="p-6 space-y-6 max-w-5xl mx-auto">

      {/* Back + title */}
      <div className="flex items-center gap-3">
        <Link href="/workers"
          className="flex items-center gap-1 text-muted-foreground hover:text-foreground text-sm transition-colors">
          <ChevronLeft size={15}/> Workers
        </Link>
        <Separator orientation="vertical" className="h-4"/>
        <h1 className="text-xl font-bold text-foreground">{worker.name}</h1>
        <Badge className={`text-xs border-0 capitalize ${statusColor}`}>{worker.status}</Badge>
      </div>

      {/* Header cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">

        {/* Identity */}
        <div className="bg-card rounded-xl border border-border p-4 space-y-3">
          <div className="flex items-center gap-2">
            <div className="w-9 h-9 rounded-full bg-brand-dark flex items-center justify-center text-white font-bold text-sm">
              {worker.name.charAt(0).toUpperCase()}
            </div>
            <div>
              <p className="font-semibold text-sm text-foreground">{worker.name}</p>
              <p className="text-xs text-muted-foreground">Grade {worker.grade} · {worker.worker_type}</p>
            </div>
          </div>
          <Separator/>
          <InfoRow icon={<User     size={13}/>} label="CNIC"     value={worker.cnic}           mono/>
          <InfoRow icon={<Phone    size={13}/>} label="WhatsApp" value={worker.whatsapp ?? '—'}    />
          <InfoRow icon={<Calendar size={13}/>} label="Joined"   value={worker.join_date}           />
        </div>

        {/* Statement snapshot */}
        <div className="bg-card rounded-xl border border-border p-4 space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground flex items-center gap-1.5">
              <CheckCircle2 size={13}/> Week Summary
            </h3>
            <input
              type="week"
              value={weekRef}
              onChange={e => setWeekRef(e.target.value)}
              className="text-xs border border-border rounded px-1.5 py-0.5 bg-background text-foreground"
            />
          </div>
          <Separator/>
          {statement.isPending ? (
            <Skeleton className="h-16"/>
          ) : stmt ? (
            <>
              <StatLine label="Gross"      value={formatPKR(stmt.gross_earnings)}/>
              <StatLine label="Deductions" value={formatPKR(stmt.deductions)}/>
              <StatLine label="Net Pay"    value={formatPKR(stmt.net_pay)} highlight/>
            </>
          ) : (
            <p className="text-xs text-muted-foreground">No statement for {weekRef}.</p>
          )}
        </div>

        {/* Payment info */}
        <div className="bg-card rounded-xl border border-border p-4 space-y-3">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground flex items-center gap-1.5">
            <Banknote size={13}/> Payment
          </h3>
          <Separator/>
          <InfoRow label="Method"  value={worker.payment_method ?? '—'}/>
          <InfoRow label="Account" value={worker.payment_number ?? '—'} mono/>
          <InfoRow label="Shift"   value={worker.default_shift  ?? '—'}/>
          <InfoRow label="Line"    value={worker.default_line_id ? `#${worker.default_line_id}` : '—'}/>
        </div>
      </div>

      {/* Tabs */}
      <Tabs defaultValue="production">
        <TabsList className="bg-muted border border-border">
          <TabsTrigger value="production">Production</TabsTrigger>
          <TabsTrigger value="statement">Statement</TabsTrigger>
          <TabsTrigger value="advances">Advances</TabsTrigger>
          <TabsTrigger value="shifts">Shift Adjustments</TabsTrigger>
          <TabsTrigger value="loans" className="flex items-center gap-1.5">
            <CreditCard size={13}/> Loans
          </TabsTrigger>
          <TabsTrigger value="compliance" className="flex items-center gap-1.5">
            <ShieldCheck size={13}/> Compliance
          </TabsTrigger>
        </TabsList>

        {/* Production history */}
        <TabsContent value="production" className="mt-4">
          <div className="bg-card rounded-xl border border-border overflow-hidden">
            {production.isPending ? (
              <TableSkeleton cols={6} rows={5}/>
            ) : prodRows.length === 0 ? (
              <EmptyState message={`No production records for ${weekRef}.`}/>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/50">
                    {['Date', 'Shift', 'Task', 'Pairs', 'Earnings', 'Status'].map(h => (
                      <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {prodRows.map(r => (
                    <tr key={r.id} className="border-b border-border last:border-0 hover:bg-muted/20">
                      <td className="px-4 py-2.5 text-muted-foreground">{r.work_date}</td>
                      <td className="px-4 py-2.5 capitalize text-muted-foreground text-xs">{r.shift}</td>
                      <td className="px-4 py-2.5">{r.task}</td>
                      <td className="px-4 py-2.5 font-medium">{r.pairs_produced.toLocaleString()}</td>
                      <td className="px-4 py-2.5">{formatPKR(Number(r.gross_earnings))}</td>
                      <td className="px-4 py-2.5">
                        <span className="flex items-center gap-1.5">
                          {STATUS_ICON[r.validation_status]}
                          <span className="text-xs capitalize">{r.validation_status}</span>
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </TabsContent>

        {/* Statement */}
        <TabsContent value="statement" className="mt-4">
          <div className="space-y-4">
            {/* Actions */}
            <div className="flex items-center gap-2 flex-wrap">
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
                {genDone ? 'Generated!' : 'Generate Statement'}
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={handleWhatsApp}
                disabled={waLoading || !stmt}
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
              {stmt?.generated_at && (
                <span className="text-xs text-muted-foreground ml-1">
                  Generated {new Date(stmt.generated_at).toLocaleDateString()}
                  {stmt.whatsapp_sent_at && ` · WhatsApp ${new Date(stmt.whatsapp_sent_at).toLocaleDateString()}`}
                </span>
              )}
            </div>

            <div className="bg-card rounded-xl border border-border overflow-hidden">
              {statement.isPending ? (
                <TableSkeleton cols={3} rows={4}/>
              ) : !stmt ? (
                <EmptyState message={`No statement for ${weekRef}. Click "Generate Statement" to create one.`}/>
              ) : (
                <>
                  <div className="px-5 py-3 border-b border-border bg-muted/40 flex items-center justify-between">
                    <div>
                      <p className="font-semibold text-sm text-foreground">{worker.name}</p>
                      <p className="text-xs text-muted-foreground font-mono">{weekRef}</p>
                    </div>
                    <div className="text-right">
                      <p className="text-xs text-muted-foreground">Net Pay</p>
                      <p className="text-lg font-bold text-brand-dark">{formatPKR(stmt.net_pay)}</p>
                    </div>
                  </div>
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-border bg-muted/20">
                        {['Description', 'Type', 'Amount'].map(h => (
                          <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {stmt.lines.map((line, i) => (
                        <tr key={i} className="border-b border-border last:border-0 hover:bg-muted/20">
                          <td className="px-4 py-2.5 text-foreground">{line.description}</td>
                          <td className="px-4 py-2.5">
                            <Badge className={`text-xs border-0 capitalize ${
                              line.type === 'credit' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
                            }`}>
                              {line.type}
                            </Badge>
                          </td>
                          <td className={`px-4 py-2.5 font-mono text-right font-medium ${
                            line.type === 'credit' ? 'text-green-700' : 'text-red-600'
                          }`}>
                            {line.type === 'debit' ? '−' : '+'}{formatPKR(Math.abs(line.amount))}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot>
                      <tr className="border-t-2 border-border bg-muted/40">
                        <td colSpan={2} className="px-4 py-3 font-bold text-foreground">Net Pay</td>
                        <td className="px-4 py-3 font-mono font-bold text-right text-brand-dark">
                          {formatPKR(stmt.net_pay)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </>
              )}
            </div>
          </div>
        </TabsContent>

        {/* Advances */}
        <TabsContent value="advances" className="mt-4">
          <div className="bg-card rounded-xl border border-border overflow-hidden">
            {advances.isPending ? (
              <TableSkeleton cols={5} rows={4}/>
            ) : advRows.length === 0 ? (
              <EmptyState message="No advances on record."/>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/50">
                    {['Week', 'Amount', 'Deducted', 'Carry Weeks', 'Status'].map(h => (
                      <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {advRows.map(a => (
                    <tr key={a.id} className="border-b border-border last:border-0 hover:bg-muted/20">
                      <td className="px-4 py-2.5 font-mono text-xs">{a.week_ref}</td>
                      <td className="px-4 py-2.5">{formatPKR(Number(a.amount))}</td>
                      <td className="px-4 py-2.5 text-muted-foreground">{formatPKR(Number(a.amount_deducted))}</td>
                      <td className="px-4 py-2.5 text-muted-foreground">{a.carry_weeks}</td>
                      <td className="px-4 py-2.5">
                        <Badge className={`text-xs border-0 ${
                          a.status === 'fully_deducted' ? 'bg-green-100 text-green-700' :
                          a.status === 'approved'       ? 'bg-blue-100 text-blue-700'  :
                          'bg-amber-100 text-amber-700'
                        }`}>
                          {a.status.replace(/_/g, ' ')}
                        </Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </TabsContent>

        {/* Shift adjustments */}
        <TabsContent value="shifts" className="mt-4">
          <div className="bg-card rounded-xl border border-border overflow-hidden">
            {shifts.isPending ? (
              <TableSkeleton cols={4} rows={4}/>
            ) : shiftRows.length === 0 ? (
              <EmptyState message="No shift adjustments."/>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/50">
                    {['Date', 'Shift', 'Adjustment', 'Reason'].map(h => (
                      <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {shiftRows.map(s => (
                    <tr key={s.id} className="border-b border-border last:border-0 hover:bg-muted/20">
                      <td className="px-4 py-2.5 text-muted-foreground">{s.work_date}</td>
                      <td className="px-4 py-2.5 capitalize text-muted-foreground text-xs">{s.shift}</td>
                      <td className={`px-4 py-2.5 font-medium ${Number(s.shift_adjustment) >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                        {Number(s.shift_adjustment) >= 0 ? '+' : ''}{formatPKR(Number(s.shift_adjustment))}
                      </td>
                      <td className="px-4 py-2.5 text-muted-foreground text-xs">{s.shift_adj_reason ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </TabsContent>

        {/* Loans */}
        <TabsContent value="loans" className="mt-4">
          <div className="bg-card rounded-xl border border-border overflow-hidden">
            {loans.isPending ? (
              <TableSkeleton cols={6} rows={4}/>
            ) : loanRows.length === 0 ? (
              <EmptyState message="No loans on record for this worker."/>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/50">
                    {['Amount', 'Weekly EMI', 'Outstanding', 'Weeks Paid', 'Total Weeks', 'Status'].map(h => (
                      <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {loanRows.map(loan => {
                    const pct = loan.total_weeks > 0
                      ? Math.round((loan.weeks_paid / loan.total_weeks) * 100)
                      : 0;
                    return (
                      <tr key={loan.id} className="border-b border-border last:border-0 hover:bg-muted/20">
                        <td className="px-4 py-2.5 font-medium">{formatPKR(Number(loan.amount))}</td>
                        <td className="px-4 py-2.5 text-muted-foreground">{formatPKR(Number(loan.weekly_emi))}</td>
                        <td className="px-4 py-2.5 font-medium text-amber-600">{formatPKR(Number(loan.outstanding_balance))}</td>
                        <td className="px-4 py-2.5">
                          <span className="flex items-center gap-2">
                            <span>{loan.weeks_paid} / {loan.total_weeks}</span>
                            <span className="text-xs text-muted-foreground">({pct}%)</span>
                          </span>
                        </td>
                        <td className="px-4 py-2.5 text-muted-foreground">{loan.total_weeks}</td>
                        <td className="px-4 py-2.5">
                          <Badge className={`text-xs border-0 capitalize ${
                            loan.status === 'paid'     ? 'bg-green-100 text-green-700' :
                            loan.status === 'active'   ? 'bg-blue-100 text-blue-700'  :
                            'bg-red-100 text-red-700'
                          }`}>
                            {loan.status}
                          </Badge>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>
        </TabsContent>

        {/* Compliance */}
        <TabsContent value="compliance" className="mt-4">
          <div className="bg-card rounded-xl border border-border p-6">
            {compliance.isPending ? (
              <div className="space-y-3">
                {[1, 2, 3, 4].map(i => <Skeleton key={i} className="h-8 w-full rounded"/>)}
              </div>
            ) : !comp ? (
              <EmptyState message="No compliance record found for this worker."/>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-5">
                {/* EOBI */}
                <ComplianceField
                  label="EOBI Number"
                  value={comp.eobi_number}
                  registeredAt={comp.eobi_registered_at}
                  missingWarning="EOBI not registered — payroll exception will be raised"
                />
                {/* PESSI */}
                <ComplianceField
                  label="PESSI Number"
                  value={comp.pessi_number}
                  registeredAt={comp.pessi_registered_at}
                  missingWarning="PESSI not registered"
                />
                {/* NTN */}
                <div className="space-y-1">
                  <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">NTN Number</p>
                  <p className="font-mono text-sm font-medium text-foreground">{comp.ntn_number ?? '—'}</p>
                </div>
                {/* Tax status */}
                <div className="space-y-1">
                  <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Tax Status</p>
                  <Badge className={`text-xs border-0 capitalize ${
                    comp.tax_status === 'exempt' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'
                  }`}>
                    {comp.tax_status ?? 'unknown'}
                  </Badge>
                </div>
                {/* WHT */}
                <div className="space-y-1">
                  <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">WHT Applicable</p>
                  <Badge className={`text-xs border-0 ${comp.wht_applicable ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'}`}>
                    {comp.wht_applicable ? 'Yes — withholding tax applies' : 'No'}
                  </Badge>
                </div>
              </div>
            )}
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}

// ── Small primitives ──────────────────────────────────────────────────────────

function InfoRow({
  icon, label, value, mono,
}: {
  icon?: React.ReactNode; label: string; value: string; mono?: boolean;
}) {
  return (
    <div className="flex items-start gap-2 text-sm">
      {icon && <span className="text-muted-foreground mt-0.5">{icon}</span>}
      <span className="text-muted-foreground min-w-14 shrink-0">{label}</span>
      <span className={`font-medium text-foreground break-all ${mono ? 'font-mono text-xs' : ''}`}>{value}</span>
    </div>
  );
}

function StatLine({ label, value, highlight }: { label: string; value: string | number; highlight?: boolean }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className={`text-lg font-bold ${highlight ? 'text-brand-dark' : 'text-foreground'}`}>{value}</span>
    </div>
  );
}

function TableSkeleton({ cols, rows }: { cols: number; rows: number }) {
  return (
    <div className="p-4 space-y-2">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex gap-3">
          {Array.from({ length: cols }).map((_, j) => (
            <Skeleton key={j} className="h-7 flex-1 rounded"/>
          ))}
        </div>
      ))}
    </div>
  );
}

function EmptyState({ message }: { message: string }) {
  return (
    <div className="px-4 py-10 text-center text-muted-foreground text-sm">{message}</div>
  );
}

function ComplianceField({
  label, value, registeredAt, missingWarning,
}: {
  label: string;
  value: string | null;
  registeredAt: string | null;
  missingWarning: string;
}) {
  return (
    <div className="space-y-1">
      <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">{label}</p>
      {value ? (
        <>
          <p className="font-mono text-sm font-medium text-foreground flex items-center gap-2">
            <CheckCircle2 size={13} className="text-green-600 shrink-0"/>
            {value}
          </p>
          {registeredAt && (
            <p className="text-xs text-muted-foreground">
              Registered: {new Date(registeredAt).toLocaleDateString()}
            </p>
          )}
        </>
      ) : (
        <p className="text-sm text-amber-600 flex items-center gap-2">
          <XCircle size={13} className="shrink-0"/>
          {missingWarning}
        </p>
      )}
    </div>
  );
}
