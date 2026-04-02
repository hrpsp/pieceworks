'use client';

import { useCurrentPayroll } from '@/hooks/usePayroll';
import { useWorkers }        from '@/hooks/useWorkers';
import { useQuery }          from '@tanstack/react-query';
import { apiClient }         from '@/lib/api-client';
import { Skeleton }          from '@/components/ui/skeleton';
import { StatCard }          from '@/components/pieceworks/StatCard';
import {
  Users, Layers, AlertTriangle, TrendingUp,
} from 'lucide-react';
import {
  BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell,
} from 'recharts';

// ── Types ────────────────────────────────────────────────────────────────────

interface ReconciliationResponse {
  status: string;
  data: {
    date: string;
    totals: { records: number; pairs: number; earnings: number; pending_count: number; flagged_count: number };
    by_line: Record<string, {
      line_name: string;
      line_total_pairs: number;
      line_total_earnings: number;
      shifts: Record<string, { worker_count: number; record_count: number; total_pairs: number; total_earnings: number }>;
    }>;
    active_rate_card: { id: number; version: string; effective_date: string } | null;
    needs_review: unknown[];
  };
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function DashboardPage() {
  const today      = new Date().toISOString().split('T')[0];
  const payroll    = useCurrentPayroll();
  const workersMeta = useWorkers({ per_page: 1 });

  const recon = useQuery({
    queryKey: ['reconciliation', today],
    queryFn: () => apiClient.get<ReconciliationResponse>(`/production/reconciliation/${today}`),
    staleTime: 2 * 60 * 1000,
  });

  const workerCount     = workersMeta.data?.meta?.total ?? 0;
  const totalPairs      = recon.data?.data.totals.pairs ?? 0;
  const totalEarnings   = recon.data?.data.totals.earnings ?? 0;
  const openExceptions  = payroll.data?.data?.stats?.unresolved_exception_count ?? 0;
  const runStatus       = payroll.data?.data?.run?.status;

  // Bar chart: pairs by line
  const chartData = Object.values(recon.data?.data.by_line ?? {}).map(line => ({
    name: line.line_name,
    pairs: line.line_total_pairs,
    earnings: Math.round(line.line_total_earnings),
  }));

  const BRAND_COLORS = ['#322E53', '#49426E', '#EEC293', '#F3AB9D'];

  return (
    <div className="p-6 space-y-6 max-w-7xl mx-auto">

      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-foreground">Dashboard</h1>
        <p className="text-sm text-muted-foreground mt-0.5">
          {new Date().toLocaleDateString('en-PK', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
        </p>
      </div>

      {/* Stats row */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {workersMeta.isPending ? (
          Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-24 rounded-xl" />
          ))
        ) : (
          <>
            <StatCard label="Active Workers"    value={workerCount}                                       icon={Users}         />
            <StatCard label="Pairs Today"        value={totalPairs.toLocaleString()}                       icon={Layers}        />
            <StatCard label="Earnings Today"     value={`₨ ${Math.round(totalEarnings).toLocaleString()}`} icon={TrendingUp} accent />
            <StatCard
              label="Open Exceptions"
              value={openExceptions}
              sub={runStatus ? `Payroll: ${runStatus}` : 'No active run'}
              icon={AlertTriangle}
              accent={openExceptions > 0}
            />
          </>
        )}
      </div>

      {/* Main content grid */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {/* Production chart */}
        <div className="lg:col-span-2 bg-card rounded-xl border border-border p-5">
          <div className="flex items-center justify-between mb-4">
            <h2 className="font-semibold text-foreground">Today&apos;s Production by Line</h2>
            <span className="text-xs text-muted-foreground">{today}</span>
          </div>

          {recon.isPending ? (
            <Skeleton className="h-48 w-full" />
          ) : chartData.length === 0 ? (
            <div className="h-48 flex items-center justify-center text-muted-foreground text-sm">
              No production data for today
            </div>
          ) : (
            <ResponsiveContainer width="100%" height={220}>
              <BarChart data={chartData} margin={{ top: 4, right: 4, bottom: 4, left: 0 }}>
                <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip
                  formatter={(v) => [String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ','), 'Pairs']}
                  contentStyle={{ fontSize: 12, borderRadius: 8 }}
                />
                <Bar dataKey="pairs" radius={[4, 4, 0, 0]}>
                  {chartData.map((_, i) => (
                    <Cell key={i} fill={BRAND_COLORS[i % BRAND_COLORS.length]} />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          )}
        </div>

        {/* Payroll status panel */}
        <div className="bg-card rounded-xl border border-border p-5 space-y-4">
          <h2 className="font-semibold text-foreground">Payroll Status</h2>

          {payroll.isPending ? (
            <div className="space-y-3">
              {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-8" />)}
            </div>
          ) : (
            <>
              <div className="space-y-3">
                <Row label="Week" value={payroll.data?.data.week_ref ?? '—'} />
                <Row label="Period"
                  value={payroll.data?.data.run
                    ? `${payroll.data.data.start_date} → ${payroll.data.data.end_date}`
                    : 'Not started'} />
                <Row label="Status">
                  <RunStatusBadge status={runStatus} />
                </Row>
                <Row label="Workers" value={payroll.data?.data.stats?.worker_count ?? 0} />
                <Row label="Total Net"
                  value={payroll.data?.data.run
                    ? `₨ ${Number(payroll.data.data.run.total_net).toLocaleString()}`
                    : '—'} />
              </div>

              {openExceptions > 0 && (
                <div className="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 flex items-center gap-2">
                  <AlertTriangle size={14} className="text-amber-600 shrink-0" />
                  <p className="text-xs text-amber-700">
                    {openExceptions} exception{openExceptions !== 1 ? 's' : ''} need review
                  </p>
                </div>
              )}

              <a href="/payroll" className="block w-full text-center text-xs font-medium
                text-brand-dark bg-brand-peach/20 hover:bg-brand-peach/30 rounded-lg py-2
                transition-colors">
                Open Payroll →
              </a>
            </>
          )}
        </div>
      </div>

      {/* Exception queue */}
      {openExceptions > 0 && (
        <div className="bg-card rounded-xl border border-amber-200 p-5">
          <h2 className="font-semibold text-foreground flex items-center gap-2 mb-3">
            <AlertTriangle size={15} className="text-amber-500" />
            Exception Queue
          </h2>
          <p className="text-sm text-muted-foreground">
            {openExceptions} payroll exception{openExceptions !== 1 ? 's' : ''} require resolution before the run can be locked.
            <a href="/payroll" className="text-brand-dark underline underline-offset-2 ml-1">
              Resolve in Payroll →
            </a>
          </p>
        </div>
      )}

    </div>
  );
}

function Row({
  label, value, children,
}: {
  label: string; value?: string | number; children?: React.ReactNode;
}) {
  return (
    <div className="flex items-center justify-between text-sm">
      <span className="text-muted-foreground">{label}</span>
      {children ?? <span className="font-medium text-foreground">{value}</span>}
    </div>
  );
}

function RunStatusBadge({ status }: { status?: string }) {
  if (!status) return <span className="text-sm font-medium text-muted-foreground">—</span>;
  const map: Record<string, string> = {
    open:       'bg-blue-100 text-blue-700',
    processing: 'bg-amber-100 text-amber-700',
    locked:     'bg-purple-100 text-purple-700',
    paid:       'bg-green-100 text-green-700',
  };
  return (
    <span className={`text-xs font-semibold px-2 py-0.5 rounded-full capitalize ${map[status] ?? 'bg-muted text-muted-foreground'}`}>
      {status}
    </span>
  );
}
