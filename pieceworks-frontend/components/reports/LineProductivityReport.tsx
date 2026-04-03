'use client';
import { useState } from 'react';
import { useLineProductivityReport } from '@/hooks/useReports';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/pieceworks/StatusBadge';
import { Download, TrendingUp, Target, Activity } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip,
  ResponsiveContainer, ReferenceLine, Cell,
} from 'recharts';

// ── Summary card ──────────────────────────────────────────────────────────────

function StatCard({
  label, value, sub, icon: Icon, color,
}: {
  label: string;
  value: string | number;
  sub?: string;
  icon: React.ElementType;
  color: string;
}) {
  return (
    <div className="rounded-xl border border-border bg-card p-4 flex items-start gap-3">
      <div className={`p-2 rounded-lg ${color} shrink-0`}>
        <Icon size={15} />
      </div>
      <div>
        <p className="text-xs text-muted-foreground font-medium">{label}</p>
        <p className="text-xl font-bold text-foreground mt-0.5">{value}</p>
        {sub && <p className="text-xs text-muted-foreground mt-0.5">{sub}</p>}
      </div>
    </div>
  );
}

// ── Custom Tooltip ────────────────────────────────────────────────────────────

function ChartTooltip({ active, payload, label }: any) {
  if (!active || !payload?.length) return null;
  return (
    <div className="bg-white border border-border rounded-lg shadow-lg px-3 py-2 text-xs">
      <p className="font-semibold text-foreground mb-1">{label}</p>
      {payload.map((p: any) => (
        <p key={p.name} style={{ color: p.color }}>
          {p.name}: <span className="font-bold">{p.value?.toLocaleString()}</span>
        </p>
      ))}
    </div>
  );
}

// ── Component ─────────────────────────────────────────────────────────────────

export function LineProductivityReport() {
  const [weekRef, setWeekRef] = useState('');
  const { data, isPending } = useLineProductivityReport({ week_ref: weekRef || undefined });
  const rows: any[] = (data as any)?.data ?? [];

  // Computed summary
  const totalPairs   = rows.reduce((s, r) => s + (r.total_pairs ?? 0), 0);
  const avgEff       = rows.length > 0
    ? rows.reduce((s, r) => s + (r.efficiency_pct ?? 0), 0) / rows.length
    : 0;
  const onTarget     = rows.filter(r => (r.efficiency_pct ?? 0) >= 100).length;

  const showSummary = !!weekRef && !isPending && rows.length > 0;

  // Chart data — top 10 lines by pairs produced
  const chartData = [...rows]
    .sort((a, b) => (b.total_pairs ?? 0) - (a.total_pairs ?? 0))
    .slice(0, 10)
    .map(r => ({
      name:        r.line_name ?? 'Line',
      pairs:       r.total_pairs ?? 0,
      target:      r.target_pairs ?? 0,
      efficiency:  r.efficiency_pct ?? 0,
    }));

  function barColor(eff: number) {
    if (eff >= 100) return '#16a34a';
    if (eff >= 80)  return '#d97706';
    return '#dc2626';
  }

  return (
    <div className="space-y-4">
      {/* Controls */}
      <div className="flex items-center gap-3">
        <input
          type="week"
          className="border border-border rounded-lg px-3 py-2 text-sm bg-background text-foreground"
          value={weekRef}
          onChange={e => setWeekRef(e.target.value)}
        />
        <Button variant="outline" size="sm" className="gap-1.5" disabled={!weekRef}>
          <Download size={14} /> CSV
        </Button>
      </div>

      {/* Summary cards */}
      {showSummary && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <StatCard
            label="Total Pairs Produced"
            value={totalPairs.toLocaleString()}
            sub={`across ${rows.length} line${rows.length !== 1 ? 's' : ''}`}
            icon={Activity}
            color="bg-[#322E53]/10 text-[#322E53]"
          />
          <StatCard
            label="Average Efficiency"
            value={`${avgEff.toFixed(1)}%`}
            sub={avgEff >= 100 ? 'All lines on target' : avgEff >= 80 ? 'Near target' : 'Below target — review needed'}
            icon={TrendingUp}
            color={avgEff >= 100 ? 'bg-green-100 text-green-700' : avgEff >= 80 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'}
          />
          <StatCard
            label="Lines On Target"
            value={`${onTarget} / ${rows.length}`}
            sub={`${rows.length > 0 ? Math.round((onTarget / rows.length) * 100) : 0}% meeting target`}
            icon={Target}
            color="bg-blue-100 text-blue-700"
          />
        </div>
      )}

      {/* Bar chart */}
      {showSummary && chartData.length > 0 && (
        <div className="rounded-xl border border-border bg-card p-4">
          <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-3">
            Pairs Produced by Line (Top 10)
          </p>
          <ResponsiveContainer width="100%" height={220}>
            <BarChart data={chartData} margin={{ top: 4, right: 8, bottom: 40, left: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0"/>
              <XAxis
                dataKey="name"
                tick={{ fontSize: 10, fill: '#6b7280' }}
                angle={-35}
                textAnchor="end"
                interval={0}
              />
              <YAxis tick={{ fontSize: 10, fill: '#6b7280' }} tickFormatter={(v: any) => v.toLocaleString()}/>
              <Tooltip content={<ChartTooltip/>}/>
              <ReferenceLine y={0} stroke="#e5e7eb"/>
              <Bar dataKey="pairs" name="Pairs" radius={[3, 3, 0, 0]}>
                {chartData.map((entry, i) => (
                  <Cell key={i} fill={barColor(entry.efficiency)}/>
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
          <div className="flex items-center gap-4 mt-2 justify-end text-xs text-muted-foreground">
            <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-green-600 inline-block"/> ≥100% efficiency</span>
            <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-amber-600 inline-block"/> 80–99%</span>
            <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-red-600 inline-block"/> &lt;80%</span>
          </div>
        </div>
      )}

      {/* Table */}
      {isPending && weekRef ? (
        <div className="space-y-2">
          {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-10 rounded-lg"/>)}
        </div>
      ) : (
        <div className="rounded-lg border border-border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-[#322E53] text-white">
              <tr>
                {['Line', 'Factory', 'Total Pairs', 'Target Pairs', 'Efficiency %', 'Status'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && weekRef ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">No data for selected week</td>
                </tr>
              ) : rows.map((r: any, i: number) => {
                const eff = r.efficiency_pct ?? 0;
                const effStatus = eff >= 100 ? 'active' : eff >= 80 ? 'pending' : 'rejected';
                return (
                  <tr key={i} className={i % 2 === 0 ? 'bg-white' : 'bg-[#F5F4F8]'}>
                    <td className="px-4 py-3 font-medium">{r.line_name}</td>
                    <td className="px-4 py-3 text-muted-foreground">{r.factory_name}</td>
                    <td className="px-4 py-3">{r.total_pairs?.toLocaleString()}</td>
                    <td className="px-4 py-3">{r.target_pairs?.toLocaleString() ?? '—'}</td>
                    <td className="px-4 py-3">
                      <span className={`font-bold ${eff >= 100 ? 'text-green-600' : eff >= 80 ? 'text-amber-600' : 'text-red-600'}`}>
                        {eff.toFixed(1)}%
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge
                        status={effStatus}
                        label={eff >= 100 ? 'ON TARGET' : eff >= 80 ? 'NEAR TARGET' : 'BELOW TARGET'}
                      />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
