'use client';
import { useState } from 'react';
import { useGhostWorkerReport } from '@/hooks/useReports';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/pieceworks/StatusBadge';
import { Download, AlertTriangle, ShieldAlert, ShieldCheck, Flag } from 'lucide-react';
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

export function GhostWorkerReport() {
  const [weekRef, setWeekRef] = useState('');
  const { data, isPending } = useGhostWorkerReport({ week_ref: weekRef || undefined });
  const rows: any[] = (data as any)?.data ?? [];

  // Computed summary
  const totalFlags  = rows.length;
  const highRisk    = rows.filter((r: any) => r.risk_level === 'high').length;
  const resolved    = rows.filter((r: any) => r.override_by_name).length;
  const resolvedPct = totalFlags > 0 ? Math.round((resolved / totalFlags) * 100) : 0;

  const showSummary = !!weekRef && !isPending && totalFlags > 0;

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
            label="Total Flags"
            value={totalFlags}
            sub={weekRef}
            icon={Flag}
            color="bg-amber-100 text-amber-700"
          />
          <StatCard
            label="High Risk"
            value={highRisk}
            sub={`${totalFlags > 0 ? Math.round((highRisk/totalFlags)*100) : 0}% of flags`}
            icon={ShieldAlert}
            color={highRisk > 0 ? 'bg-red-100 text-red-700' : 'bg-muted text-muted-foreground'}
          />
          <StatCard
            label="Resolved / Overridden"
            value={`${resolvedPct}%`}
            sub={`${resolved} of ${totalFlags} reviewed`}
            icon={ShieldCheck}
            color="bg-green-100 text-green-700"
          />
        </div>
      )}

      {/* High-risk alert banner */}
      {highRisk > 0 && weekRef && !isPending && (
        <div className="flex items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
          <AlertTriangle size={15}/>
          <strong>{highRisk} high-risk</strong> ghost worker flag{highRisk > 1 ? 's' : ''} detected — immediate review required
        </div>
      )}

      {/* Table */}
      {isPending && weekRef ? (
        <div className="space-y-2">
          {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-10 rounded-lg"/>)}
        </div>
      ) : (
        <div className="rounded-lg border border-border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-[#322E53] text-white">
              <tr>
                {['Worker', 'Contractor', 'Date', 'Biometric', 'Production Anomaly', 'Risk Level', 'Override By'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && weekRef ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">
                    No ghost worker flags for selected week
                  </td>
                </tr>
              ) : rows.map((r: any, i: number) => (
                <tr key={i} className={i % 2 === 0 ? 'bg-white' : 'bg-[#F5F4F8]'}>
                  <td className="px-4 py-3 font-medium">{r.worker_name}</td>
                  <td className="px-4 py-3 text-muted-foreground">{r.contractor_name}</td>
                  <td className="px-4 py-3 text-xs">{r.work_date}</td>
                  <td className="px-4 py-3">
                    {r.biometric_present
                      ? <StatusBadge status="clean" label="PRESENT"/>
                      : <StatusBadge status="error" label="MISSING"/>}
                  </td>
                  <td className="px-4 py-3">
                    {r.production_anomaly
                      ? <StatusBadge status="warning" label="ANOMALY"/>
                      : <span className="text-muted-foreground text-xs">—</span>}
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge
                      status={r.risk_level === 'high' ? 'error' : r.risk_level === 'medium' ? 'warning' : 'clean'}
                      label={r.risk_level?.toUpperCase()}
                    />
                  </td>
                  <td className="px-4 py-3 text-muted-foreground">{r.override_by_name ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
