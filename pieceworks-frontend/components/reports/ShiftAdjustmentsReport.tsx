'use client';
import { useState } from 'react';
import { useShiftAdjustmentsReport } from '@/hooks/useReports';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/pieceworks/StatusBadge';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function ShiftAdjustmentsReport() {
  const [weekRef, setWeekRef] = useState('');
  const { data, isPending } = useShiftAdjustmentsReport({ week_ref: weekRef || undefined });
  const rows = (data as any)?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <input type="week" className="border rounded-lg px-3 py-2 text-sm"
          value={weekRef} onChange={e => setWeekRef(e.target.value)} />
        <Button variant="outline" size="sm" className="gap-1.5" disabled={!weekRef}>
          <Download size={14} /> CSV
        </Button>
      </div>
      {isPending && weekRef ? (
        <div className="space-y-2">{Array.from({length:5}).map((_,i)=><Skeleton key={i} className="h-10"/>)}</div>
      ) : (
        <div className="rounded-lg border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-[#322E53] text-white">
              <tr>
                {['Worker','Date','Scheduled','Actual','OT Flagged','Hours Gap','Authorized By'].map(h=>(
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && weekRef ? (
                <tr><td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">No shift adjustments for selected week</td></tr>
              ) : rows.map((r: any, i: number) => (
                <tr key={i} className={i%2===0?'bg-white':'bg-[#F5F4F8]'}>
                  <td className="px-4 py-3 font-medium">{r.worker_name}</td>
                  <td className="px-4 py-3 text-xs">{r.work_date}</td>
                  <td className="px-4 py-3 capitalize">{r.scheduled_shift}</td>
                  <td className="px-4 py-3 capitalize">{r.actual_shift}</td>
                  <td className="px-4 py-3">
                    {r.overtime_flagged ? <StatusBadge status="flagged" label="OT FLAGGED"/> : <span className="text-muted-foreground text-xs">—</span>}
                  </td>
                  <td className="px-4 py-3">{r.hours_gap_from_last_shift != null ? `${r.hours_gap_from_last_shift}h` : '—'}</td>
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
