'use client';
import { useState } from 'react';
import { useRejectionAnalysisReport } from '@/hooks/useReports';
import { Skeleton } from '@/components/ui/skeleton';
import { Download, AlertOctagon, TrendingDown, User } from 'lucide-react';
import { Button } from '@/components/ui/button';

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

// ── Component ─────────────────────────────────────────────────────────────────

export function RejectionAnalysisReport() {
  const currentMonth = new Date().toISOString().slice(0, 7);
  const [month, setMonth] = useState(currentMonth);
  const { data, isPending } = useRejectionAnalysisReport({ month: month || undefined });
  const rows: any[] = (data as any)?.data ?? [];

  // Computed summary
  const totalRejections = rows.reduce((s, r) => s + (r.pairs_rejected ?? 0), 0);
  const avgRate         = rows.length > 0
    ? rows.reduce((s, r) => s + (r.rejection_rate ?? 0), 0) / rows.length
    : 0;

  // Worst offender — worker with most pairs rejected
  const worstOffender = rows.reduce<any | null>((worst, r) => {
    if (!worst || (r.pairs_rejected ?? 0) > (worst.pairs_rejected ?? 0)) return r;
    return worst;
  }, null);

  const showSummary = !isPending && rows.length > 0;

  return (
    <div className="space-y-4">
      {/* Controls */}
      <div className="flex items-center gap-3">
        <input
          type="month"
          className="border border-border rounded-lg px-3 py-2 text-sm bg-background text-foreground"
          value={month}
          onChange={e => setMonth(e.target.value)}
        />
        <Button variant="outline" size="sm" className="gap-1.5">
          <Download size={14} /> CSV
        </Button>
      </div>

      {/* Summary cards */}
      {showSummary && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <StatCard
            label="Total Pairs Rejected"
            value={totalRejections.toLocaleString()}
            sub={`${rows.length} rejection record${rows.length !== 1 ? 's' : ''} in ${month}`}
            icon={AlertOctagon}
            color="bg-red-100 text-red-700"
          />
          <StatCard
            label="Average Rejection Rate"
            value={`${avgRate.toFixed(2)}%`}
            sub={avgRate >= 10 ? 'Critical — review quality process' : avgRate >= 5 ? 'Elevated — monitor closely' : 'Within acceptable range'}
            icon={TrendingDown}
            color={avgRate >= 10 ? 'bg-red-100 text-red-700' : avgRate >= 5 ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'}
          />
          <StatCard
            label="Worst Offender"
            value={worstOffender?.worker_name ?? '—'}
            sub={worstOffender ? `${worstOffender.pairs_rejected?.toLocaleString()} pairs rejected (${worstOffender.rejection_rate?.toFixed(1)}%)` : undefined}
            icon={User}
            color="bg-amber-100 text-amber-700"
          />
        </div>
      )}

      {/* Table */}
      {isPending ? (
        <div className="space-y-2">
          {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-10 rounded-lg"/>)}
        </div>
      ) : (
        <div className="rounded-lg border border-border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-[#322E53] text-white">
              <tr>
                {['Worker', 'Task', 'Style', 'Line', 'Pairs Rejected', 'Rejection Rate'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                    No rejections recorded for {month}
                  </td>
                </tr>
              ) : rows.map((r: any, i: number) => {
                const rate = r.rejection_rate ?? 0;
                return (
                  <tr key={i} className={i % 2 === 0 ? 'bg-white' : 'bg-[#F5F4F8]'}>
                    <td className="px-4 py-3 font-medium">{r.worker_name}</td>
                    <td className="px-4 py-3">{r.task}</td>
                    <td className="px-4 py-3 font-mono text-xs">{r.style_code}</td>
                    <td className="px-4 py-3 text-muted-foreground">{r.line_name}</td>
                    <td className="px-4 py-3">{r.pairs_rejected?.toLocaleString()}</td>
                    <td className="px-4 py-3">
                      <span className={`font-bold ${rate >= 10 ? 'text-red-600' : rate >= 5 ? 'text-amber-600' : 'text-green-600'}`}>
                        {rate.toFixed(1)}%
                      </span>
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
