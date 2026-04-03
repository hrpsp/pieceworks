'use client';
import { useState } from 'react';
import { useShiftAdjustmentsReport } from '@/hooks/useReports';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/pieceworks/StatusBadge';
import { Download, Clock, AlarmClock, CalendarRange } from 'lucide-react';
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

export function ShiftAdjustmentsReport() {
  const [weekRef, setWeekRef] = useState('');
  const { data, isPending } = useShiftAdjustmentsReport({ week_ref: weekRef || undefined });
  const rows: any[] = (data as any)?.data ?? [];

  // Computed summary
  const totalAdjustments = rows.length;
  const otFlagged        = rows.filter(r => r.overtime_flagged).length;
  const rowsWithGap      = rows.filter(r => r.hours_gap_from_last_shift != null);
  const avgGap           = rowsWithGap.length > 0
    ? rowsWithGap.reduce((s, r) => s + (r.hours_gap_from_last_shift ?? 0), 0) / rowsWithGap.length
    : null;

  const showSummary = !!weekRef && !isPending && totalAdjustments > 0;

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
            label="Total Adjustments"
            value={totalAdjustments}
            sub={weekRef}
            icon={CalendarRange}
            color="bg-[#322E53]/10 text-[#322E53]"
          />
          <StatCard
            label="Overtime Flags"
            value={otFlagged}
            sub={`${totalAdjustments > 0 ? Math.round((otFlagged / totalAdjustments) * 100) : 0}% of adjustments flagged`}
            icon={AlarmClock}
            color={otFlagged > 0 ? 'bg-amber-100 text-amber-700' : 'bg-muted text-muted-foreground'}
          />
          <StatCard
            label="Avg Hours Gap"
            value={avgGap != null ? `${avgGap.toFixed(1)}h` : '—'}
            sub={avgGap != null && avgGap < 8 ? 'Below rest threshold (8h)' : 'Within safe range'}
            icon={Clock}
            color={avgGap != null && avgGap < 8 ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'}
          />
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
                {['Worker', 'Date', 'Scheduled', 'Actual', 'OT Flagged', 'Hours Gap', 'Authorized By'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && weekRef ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">
                    No shift adjustments for selected week
                  </td>
                </tr>
              ) : rows.map((r: any, i: number) => (
                <tr key={i} className={i % 2 === 0 ? 'bg-white' : 'bg-[#F5F4F8]'}>
                  <td className="px-4 py-3 font-medium">{r.worker_name}</td>
                  <td className="px-4 py-3 text-xs">{r.work_date}</td>
                  <td className="px-4 py-3 capitalize">{r.scheduled_shift}</td>
                  <td className="px-4 py-3 capitalize">{r.actual_shift}</td>
                  <td className="px-4 py-3">
                    {r.overtime_flagged
                      ? <StatusBadge status="flagged" label="OT FLAGGED"/>
                      : <span className="text-muted-foreground text-xs">—</span>}
                  </td>
                  <td className="px-4 py-3">
                    {r.hours_gap_from_last_shift != null
                      ? <span className={r.hours_gap_from_last_shift < 8 ? 'text-red-600 font-semibold' : 'text-foreground'}>
                          {r.hours_gap_from_last_shift}h
                        </span>
                      : '—'}
                  </td>
                  <td className="px-4 py-3 text-muted-foreground">{r.authorized_by_name ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
