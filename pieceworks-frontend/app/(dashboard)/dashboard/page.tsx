'use client';

import { useCurrentPayroll }       from '@/hooks/usePayroll';
import { useWorkers }              from '@/hooks/useWorkers';
import { useQuery, useQueries }    from '@tanstack/react-query';
import { apiClient }               from '@/lib/api-client';
import { Skeleton }             from '@/components/ui/skeleton';
import { Badge }                from '@/components/ui/badge';
import {
  Users, Layers, AlertTriangle, TrendingUp, Wallet,
  ArrowUpRight, Building2, CheckCircle2,
  Clock, Lock, Activity, ChevronRight,
  ShieldAlert, ClipboardList, FileText, ExternalLink,
} from 'lucide-react';
import {
  AreaChart, Area, BarChart, Bar,
  XAxis, YAxis, Tooltip, ResponsiveContainer, Cell,
  PieChart, Pie, Legend,
} from 'recharts';

// ── Brand palette ─────────────────────────────────────────────────────────────
const B = {
  dark:   '#322E53',
  mid:    '#49426E',
  peach:  '#EEC293',
  salmon: '#F3AB9D',
  bg:     '#F5F4F8',
  gold:   '#F0A500',
};

// ── Week references for trend ─────────────────────────────────────────────────
const HISTORY_WEEKS = ['2026-W10', '2026-W11', '2026-W12', '2026-W13', '2026-W14'];

// ── Formatters ────────────────────────────────────────────────────────────────
function fmt(n: number): string {
  return `₨ ${Math.round(n).toLocaleString('en-PK')}`;
}
function fmtK(n: number): string {
  if (n >= 1_000_000) return `₨ ${(n / 1_000_000).toFixed(2)}M`;
  if (n >= 1_000)     return `₨ ${(n / 1_000).toFixed(1)}K`;
  return fmt(n);
}
function pct(a: number, b: number): string {
  if (!b) return '—';
  return `${Math.round((a / b) * 100)}%`;
}

// ── Sub-components ────────────────────────────────────────────────────────────

function KpiCard({
  label, value, sub, icon: Icon, gradient = false, warning = false,
}: {
  label: string;
  value: string | number;
  sub?: string;
  icon: React.ElementType;
  gradient?: boolean;
  warning?: boolean;
}) {
  const base = gradient
    ? 'text-white'
    : warning
    ? 'bg-amber-50 border-amber-200'
    : 'bg-white border-border';

  return (
    <div
      className={`relative overflow-hidden rounded-2xl border p-5 shadow-sm flex flex-col justify-between min-h-[110px] ${base}`}
      style={gradient ? { background: `linear-gradient(135deg, ${B.dark} 0%, ${B.mid} 100%)` } : undefined}
    >
      {/* Decorative ring */}
      {gradient && (
        <div
          className="absolute -top-6 -right-6 w-28 h-28 rounded-full opacity-10"
          style={{ background: B.peach }}
        />
      )}
      <div className="flex items-start justify-between relative z-10">
        <div>
          <p className={`text-xs font-semibold uppercase tracking-widest ${gradient ? 'text-white/60' : warning ? 'text-amber-600' : 'text-muted-foreground'}`}>
            {label}
          </p>
          <p className={`text-3xl font-bold mt-1 leading-tight ${gradient ? 'text-white' : warning ? 'text-amber-700' : 'text-foreground'}`}>
            {value}
          </p>
          {sub && (
            <p className={`text-xs mt-1 ${gradient ? 'text-white/60' : 'text-muted-foreground'}`}>
              {sub}
            </p>
          )}
        </div>
        <div
          className={`p-2.5 rounded-xl ${gradient ? 'bg-white/10' : warning ? 'bg-amber-100' : 'bg-brand-dark/8'}`}
          style={!gradient && !warning ? { background: `${B.dark}14` } : undefined}
        >
          <Icon size={20} className={gradient ? 'text-white' : warning ? 'text-amber-600' : 'text-brand-dark'} />
        </div>
      </div>
    </div>
  );
}

function SectionHeader({ title, sub }: { title: string; sub?: string }) {
  return (
    <div className="mb-4">
      <h2 className="font-semibold text-foreground text-sm">{title}</h2>
      {sub && <p className="text-xs text-muted-foreground mt-0.5">{sub}</p>}
    </div>
  );
}

function StatusBadge({ status }: { status?: string }) {
  if (!status) return <span className="text-sm text-muted-foreground">—</span>;
  const MAP: Record<string, { cls: string; icon: React.ReactNode }> = {
    open:       { cls: 'bg-blue-50 text-blue-700 border-blue-200',     icon: <Activity size={11} /> },
    processing: { cls: 'bg-amber-50 text-amber-700 border-amber-200',   icon: <Clock size={11} /> },
    locked:     { cls: 'bg-purple-50 text-purple-700 border-purple-200',icon: <Lock size={11} /> },
    paid:       { cls: 'bg-green-50 text-green-700 border-green-200',   icon: <CheckCircle2 size={11} /> },
  };
  const cfg = MAP[status] ?? { cls: 'bg-muted text-muted-foreground border-border', icon: null };
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-xs font-semibold capitalize ${cfg.cls}`}>
      {cfg.icon} {status}
    </span>
  );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function DashboardPage() {
  const today   = new Date().toISOString().split('T')[0];
  const payroll = useCurrentPayroll({ refetchInterval: 2 * 60 * 1000 });
  const workers = useWorkers({ status: 'active', per_page: 1 });

  // Today's production reconciliation
  const recon = useQuery({
    queryKey: ['reconciliation', today],
    queryFn:  () => apiClient.get(`/production/reconciliation/${today}`),
    staleTime: 2 * 60 * 1000,
  });

  // Ghost worker flags summary
  const ghostFlags = useQuery({
    queryKey: ['ghost-worker', 'flags', { resolved: false, per_page: 1 }],
    queryFn:  () => apiClient.get<any>('/ghost-worker/flags?resolved=0&per_page=1'),
    placeholderData: (prev: any) => prev,
  });
  const ghostTotal = ((ghostFlags.data as any)?.data?.meta?.total ?? 0) as number;

  // Historical payroll runs for trend chart (parallel)
  const historicalRuns = useQueries({
    queries: HISTORY_WEEKS.map(weekRef => ({
      queryKey: ['payroll', weekRef],
      queryFn:  () => apiClient.get<any>(`/payroll/${weekRef}`),
      staleTime: 10 * 60 * 1000,
    })),
  });

  // ── Derived values ──────────────────────────────────────────────────────────
  // Current payroll — correctly typed as ApiEnvelope<CurrentPayrollResponse>
  const currentData   = payroll.data?.data;
  const run           = currentData?.run;
  const stats         = currentData?.stats;
  const exceptions    = stats?.unresolved_exception_count ?? 0;

  // Worker count — backend wraps in {status, data: LaravelPaginator}
  const workerTotal   = (workers.data as any)?.data?.total ?? (workers.data as any)?.meta?.total ?? '—';

  // Today's production — apiEnvelope wrapping
  const reconData     = (recon.data as any)?.data;
  const todayPairs    = reconData?.totals?.pairs ?? 0;
  const todayEarnings = reconData?.totals?.earnings ?? 0;
  const byLine        = reconData?.by_line ?? {};

  // Trend chart data
  const trendData = HISTORY_WEEKS.map((weekRef, i) => {
    const runData = (historicalRuns[i]?.data as any)?.data;
    const gross   = parseFloat(runData?.run?.total_gross ?? runData?.total_gross ?? 0);
    const net     = parseFloat(runData?.run?.total_net   ?? runData?.total_net   ?? 0);
    const label   = weekRef.replace('2026-', '');
    return { week: label, gross: Math.round(gross), net: Math.round(net) };
  });

  // Line breakdown for bar chart
  const lineChartData = Object.values(byLine).map((line: any) => ({
    name:  (line.line_name as string).replace('Line ', 'L').split('–')[0].trim(),
    pairs: line.line_total_pairs,
  }));

  // ── Task distribution donut (from today's reconciliation) ──────────────────
  // Fallback: use static demo proportions if no recon data
  const taskData = reconData
    ? []
    : [
        { name: 'Lasting',       value: 28, fill: B.dark },
        { name: 'Stitching',     value: 25, fill: B.mid },
        { name: 'Sole Pressing', value: 20, fill: B.peach },
        { name: 'Finishing',     value: 18, fill: B.salmon },
        { name: 'Upper Cutting', value: 9,  fill: '#9B8EC4' },
      ];

  return (
    <div className="p-6 space-y-6 max-w-[1400px] mx-auto">

      {/* ── Page Header ────────────────────────────────────────────────────── */}
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-tight">
            Operations Command Centre
          </h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            {new Date().toLocaleDateString('en-PK', {
              weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
            })}
          </p>
        </div>
        <div className="flex items-center gap-3">
          {run && (
            <div
              className="flex items-center gap-2 px-4 py-2 rounded-xl border text-sm font-medium"
              style={{ background: `${B.dark}10`, borderColor: `${B.dark}30` }}
            >
              <span className="text-muted-foreground">Week:</span>
              <span className="font-semibold text-foreground">{currentData?.week_ref ?? '—'}</span>
              <StatusBadge status={run?.status} />
            </div>
          )}
        </div>
      </div>

      {/* ── KPI Row ────────────────────────────────────────────────────────── */}
      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        {payroll.isPending || workers.isPending ? (
          Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-[110px] rounded-2xl" />
          ))
        ) : (
          <>
            <KpiCard
              label="Active Workers"
              value={workerTotal}
              sub="Across all contractors"
              icon={Users}
              gradient
            />
            <KpiCard
              label="Week Net Payroll"
              value={stats?.total_net ? fmtK(stats.total_net) : '—'}
              sub={`Gross: ${stats?.total_gross ? fmtK(stats.total_gross) : '—'}`}
              icon={Wallet}
              gradient
            />
            <KpiCard
              label="Pairs Today"
              value={todayPairs > 0 ? todayPairs.toLocaleString() : '—'}
              sub={todayEarnings > 0 ? `Earnings: ${fmtK(todayEarnings)}` : 'No production logged'}
              icon={Layers}
            />
            <KpiCard
              label="Contractors Active"
              value="5"
              sub="All running weekly"
              icon={Building2}
            />
            <KpiCard
              label="Open Exceptions"
              value={exceptions}
              sub={exceptions > 0 ? 'Action required' : 'All clear'}
              icon={AlertTriangle}
              warning={exceptions > 0}
            />
            <KpiCard
              label="Ghost Flags"
              value={ghostFlags.isPending ? '…' : ghostTotal}
              sub={ghostTotal > 0 ? 'Unresolved — review required' : 'No active flags'}
              icon={ShieldAlert}
              warning={ghostTotal > 0}
            />
          </>
        )}
      </div>

      {/* ── Main Grid: Trend + Payroll ─────────────────────────────────────── */}
      <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">

        {/* Multi-week earnings trend */}
        <div className="xl:col-span-2 bg-white rounded-2xl border border-border p-6 shadow-sm">
          <SectionHeader
            title="5-Week Earnings Trend"
            sub="Gross vs Net payroll (PKR)"
          />

          {historicalRuns.some(r => r.isPending) ? (
            <Skeleton className="h-56 w-full rounded-xl" />
          ) : (
            <ResponsiveContainer width="100%" height={230}>
              <AreaChart data={trendData} margin={{ top: 4, right: 4, bottom: 0, left: 10 }}>
                <defs>
                  <linearGradient id="gradGross" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%"  stopColor={B.dark}  stopOpacity={0.18} />
                    <stop offset="95%" stopColor={B.dark}  stopOpacity={0.02} />
                  </linearGradient>
                  <linearGradient id="gradNet" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%"  stopColor={B.peach} stopOpacity={0.35} />
                    <stop offset="95%" stopColor={B.peach} stopOpacity={0.04} />
                  </linearGradient>
                </defs>
                <XAxis
                  dataKey="week"
                  tick={{ fontSize: 11, fill: '#888' }}
                  axisLine={false}
                  tickLine={false}
                />
                <YAxis
                  tick={{ fontSize: 10, fill: '#aaa' }}
                  axisLine={false}
                  tickLine={false}
                  tickFormatter={v => v >= 1000 ? `${(v/1000).toFixed(0)}K` : v}
                  width={52}
                />
                <Tooltip
                  contentStyle={{ fontSize: 12, borderRadius: 10, border: '1px solid #e5e7eb' }}
                  formatter={(v: any, name: any) => [fmt(v as number), name === 'gross' ? 'Gross' : 'Net']}
                />
                <Area
                  type="monotone" dataKey="gross"
                  stroke={B.dark} strokeWidth={2}
                  fill="url(#gradGross)"
                />
                <Area
                  type="monotone" dataKey="net"
                  stroke={B.peach} strokeWidth={2} strokeDasharray="4 3"
                  fill="url(#gradNet)"
                />
              </AreaChart>
            </ResponsiveContainer>
          )}

          <div className="flex items-center gap-6 mt-3 pt-3 border-t border-border">
            <div className="flex items-center gap-2">
              <div className="w-3 h-3 rounded-full" style={{ background: B.dark }} />
              <span className="text-xs text-muted-foreground">Gross Payroll</span>
            </div>
            <div className="flex items-center gap-2">
              <div className="w-3 h-0.5 border-t-2 border-dashed" style={{ borderColor: B.peach }} />
              <span className="text-xs text-muted-foreground">Net Payroll</span>
            </div>
          </div>
        </div>

        {/* Payroll status card */}
        <div className="bg-white rounded-2xl border border-border p-6 shadow-sm flex flex-col">
          <SectionHeader title="Current Payroll" sub={currentData?.week_ref ?? '—'} />

          {payroll.isPending ? (
            <div className="space-y-3 flex-1">
              {Array.from({ length: 5 }).map((_, i) => (
                <Skeleton key={i} className="h-10 rounded-lg" />
              ))}
            </div>
          ) : (
            <>
              <div className="space-y-3 flex-1">
                <PayrollRow icon={Activity} label="Status">
                  <StatusBadge status={run?.status} />
                </PayrollRow>
                <PayrollRow icon={Users} label="Workers in Run">
                  <span className="font-semibold">{stats?.worker_count ?? '—'}</span>
                </PayrollRow>
                <PayrollRow icon={TrendingUp} label="Total Gross">
                  <span className="font-mono font-semibold text-sm">
                    {stats?.total_gross ? fmt(stats.total_gross) : '—'}
                  </span>
                </PayrollRow>
                <PayrollRow icon={Wallet} label="Total Net">
                  <span className="font-mono font-bold" style={{ color: B.dark }}>
                    {stats?.total_net ? fmt(stats.total_net) : '—'}
                  </span>
                </PayrollRow>
                <PayrollRow icon={AlertTriangle} label="Exceptions">
                  <span className={`font-semibold ${exceptions > 0 ? 'text-amber-600' : 'text-green-600'}`}>
                    {exceptions > 0 ? `${exceptions} unresolved` : 'All clear'}
                  </span>
                </PayrollRow>

                <div className="pt-1">
                  <div className="h-1.5 w-full rounded-full bg-muted overflow-hidden">
                    <div
                      className="h-full rounded-full transition-all"
                      style={{
                        width: run?.status === 'paid' ? '100%'
                          : run?.status === 'locked' ? '75%'
                          : run?.status === 'processing' ? '50%'
                          : '25%',
                        background: `linear-gradient(90deg, ${B.dark}, ${B.peach})`,
                      }}
                    />
                  </div>
                  <p className="text-xs text-muted-foreground mt-1">
                    {run?.status === 'paid' ? 'Disbursed'
                      : run?.status === 'locked' ? 'Awaiting release'
                      : run?.status === 'processing' ? 'Calculating'
                      : 'Collecting production'}
                  </p>
                </div>
              </div>

              {exceptions > 0 && (
                <div className="mt-4 bg-amber-50 border border-amber-200 rounded-xl p-3 flex items-center gap-2">
                  <AlertTriangle size={14} className="text-amber-600 shrink-0" />
                  <p className="text-xs text-amber-700 flex-1">
                    {exceptions} exception{exceptions !== 1 ? 's' : ''} require resolution before locking
                  </p>
                </div>
              )}

              <a
                href="/payroll"
                className="mt-4 flex items-center justify-center gap-1.5 w-full rounded-xl py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                style={{ background: `linear-gradient(135deg, ${B.dark} 0%, ${B.mid} 100%)` }}
              >
                Open Payroll <ChevronRight size={14} />
              </a>
            </>
          )}
        </div>
      </div>

      {/* ── Secondary Grid ─────────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">

        {/* Today's production by line */}
        <div className="bg-white rounded-2xl border border-border p-6 shadow-sm">
          <SectionHeader
            title="Today's Production by Line"
            sub={todayPairs > 0 ? `${todayPairs} total pairs` : 'No data logged today'}
          />
          {recon.isPending ? (
            <Skeleton className="h-44 w-full rounded-xl" />
          ) : lineChartData.length === 0 ? (
            <div className="h-44 flex flex-col items-center justify-center text-muted-foreground/40 text-sm gap-2">
              <Layers size={28} />
              <span>Production entries appear here after data is logged</span>
            </div>
          ) : (
            <ResponsiveContainer width="100%" height={180}>
              <BarChart data={lineChartData} margin={{ top: 4, right: 4, bottom: 4, left: 0 }}>
                <XAxis dataKey="name" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 10 }} axisLine={false} tickLine={false} width={36} />
                <Tooltip
                  contentStyle={{ fontSize: 11, borderRadius: 8 }}
                  formatter={(v: any) => [(v as number).toLocaleString(), 'Pairs']}
                />
                <Bar dataKey="pairs" radius={[6, 6, 0, 0]}>
                  {lineChartData.map((_, i) => (
                    <Cell key={i} fill={[B.dark, B.mid, B.peach][i % 3]} />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          )}
        </div>

        {/* Task distribution donut */}
        <div className="bg-white rounded-2xl border border-border p-6 shadow-sm">
          <SectionHeader title="Production by Task" sub="Current week mix" />
          {taskData.length === 0 ? (
            <div className="h-44 flex items-center justify-center text-muted-foreground text-sm">
              <span>Task breakdown available after production is entered</span>
            </div>
          ) : (
            <ResponsiveContainer width="100%" height={180}>
              <PieChart>
                <Pie
                  data={taskData}
                  cx="50%" cy="45%"
                  innerRadius={42} outerRadius={68}
                  dataKey="value"
                  paddingAngle={2}
                >
                  {taskData.map((entry, i) => (
                    <Cell key={i} fill={entry.fill} />
                  ))}
                </Pie>
                <Legend
                  iconType="circle"
                  iconSize={8}
                  wrapperStyle={{ fontSize: '10px', paddingTop: '8px' }}
                />
                <Tooltip
                  contentStyle={{ fontSize: 11, borderRadius: 8 }}
                  formatter={(v: any) => [`${v as number}%`, 'Share']}
                />
              </PieChart>
            </ResponsiveContainer>
          )}
        </div>

        {/* Contractor overview */}
        <div className="bg-white rounded-2xl border border-border p-6 shadow-sm">
          <div className="flex items-center justify-between mb-4">
            <SectionHeader title="Contractor Overview" sub="All 5 active contractors" />
            <a href="/contractor" className="text-xs font-semibold flex items-center gap-1" style={{ color: B.dark }}>
              View All <ChevronRight size={12}/>
            </a>
          </div>
          <div className="space-y-1">
            {[
              { id: 1, name: 'Khan Labour Services',    workers: 4, status: 'settled' },
              { id: 2, name: 'Raza Manpower Solutions', workers: 4, status: 'settled' },
              { id: 3, name: 'Premier Skilled Workers', workers: 4, status: 'settled' },
              { id: 4, name: 'Al-Farooq Enterprises',   workers: 4, status: 'settled' },
              { id: 5, name: 'Hamza Workforce Pvt Ltd', workers: 4, status: 'pending' },
            ].map((c) => (
              <a
                key={c.id}
                href={`/contractor-portal/${c.id}`}
                className="flex items-center justify-between py-2 px-2 rounded-lg border-b border-border last:border-0 hover:bg-muted/30 transition-colors group"
              >
                <div className="flex items-center gap-2.5 flex-1 min-w-0">
                  <div
                    className="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 text-white text-xs font-bold"
                    style={{ background: `linear-gradient(135deg, ${B.dark} 0%, ${B.mid} 100%)` }}
                  >
                    {c.name[0]}
                  </div>
                  <div className="min-w-0">
                    <p className="text-xs font-medium text-foreground truncate">{c.name}</p>
                    <p className="text-xs text-muted-foreground">{c.workers} workers</p>
                  </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <Badge className={`text-xs border-0 ${c.status === 'settled' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}>
                    {c.status}
                  </Badge>
                  <ExternalLink size={11} className="text-muted-foreground/40 group-hover:text-brand-dark transition-colors"/>
                </div>
              </a>
            ))}
          </div>
        </div>
      </div>

      {/* ── Exception Alert Queue ──────────────────────────────────────────── */}
      {exceptions > 0 && (
        <div
          className="rounded-2xl border p-5"
          style={{ background: 'rgba(240,165,0,0.05)', borderColor: 'rgba(240,165,0,0.3)' }}
        >
          <div className="flex items-center justify-between mb-3">
            <h2 className="font-semibold text-foreground flex items-center gap-2 text-sm">
              <AlertTriangle size={16} className="text-amber-500" />
              Payroll Exception Queue
              <span className="bg-amber-500 text-white text-xs font-bold px-1.5 py-0.5 rounded-full">
                {exceptions}
              </span>
            </h2>
            <a
              href="/payroll"
              className="text-xs font-semibold flex items-center gap-1"
              style={{ color: B.dark }}
            >
              Resolve All <ArrowUpRight size={12} />
            </a>
          </div>
          <p className="text-sm text-muted-foreground">
            {exceptions} payroll exception{exceptions !== 1 ? 's' : ''} must be resolved before the{' '}
            <span className="font-medium text-foreground">{currentData?.week_ref}</span> run can be locked and payments released.
            Exceptions include minimum-wage shortfalls and disputed attendance records.
          </p>
        </div>
      )}

      {/* ── Footer: Quick navigation ────────────────────────────────────────── */}
      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        {[
          { label: 'Production Log',  href: '/production',   icon: Layers,         desc: 'Enter daily output' },
          { label: 'Payroll',         href: '/payroll',      icon: Wallet,         desc: 'Manage runs & pay' },
          { label: 'Workers',         href: '/workers',      icon: Users,          desc: 'Roster & records' },
          { label: 'Contractors',     href: '/contractor',   icon: Building2,      desc: 'Settlement view' },
          { label: 'Exceptions',      href: '/exceptions',   icon: AlertTriangle,  desc: 'Review & resolve' },
          { label: 'Audit Log',       href: '/audit-log',    icon: ClipboardList,  desc: 'Change history' },
        ].map(({ label, href, icon: Icon, desc }) => (
          <a
            key={label}
            href={href}
            className="flex items-center gap-3 p-4 rounded-xl border border-border bg-white hover:border-brand-dark/30 hover:shadow-sm transition-all group"
          >
            <div
              className="p-2 rounded-lg shrink-0"
              style={{ background: `${B.dark}12` }}
            >
              <Icon size={16} style={{ color: B.dark }} />
            </div>
            <div>
              <p className="text-xs font-semibold text-foreground group-hover:text-brand-dark">{label}</p>
              <p className="text-xs text-muted-foreground">{desc}</p>
            </div>
            <ChevronRight size={14} className="ml-auto text-muted-foreground/40 group-hover:text-brand-dark" />
          </a>
        ))}
      </div>

    </div>
  );
}

// ── Sub-components ────────────────────────────────────────────────────────────

function PayrollRow({
  icon: Icon, label, children,
}: {
  icon: React.ElementType;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-center justify-between text-sm">
      <div className="flex items-center gap-2 text-muted-foreground">
        <Icon size={13} />
        <span className="text-xs">{label}</span>
      </div>
      <div>{children}</div>
    </div>
  );
}
