'use client';
import { useState } from 'react';
import { useLineProductivityReport } from '@/hooks/useReports';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/pieceworks/StatusBadge';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function LineProductivityReport() {
  const [weekRef, setWeekRef] = useState('');
  const { data, isPending } = useLineProductivityReport({ week_ref: weekRef || undefined });
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
        <div className="space-y-2">{Array.from({length:4}).map((_,i)=><Skeleton key={i} className="h-10"/>)}</div>
      ) : (
        <div className="rounded-lg border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-[#322E53] text-white">
              <tr>
                {['Line','Factory','Total Pairs','Target Pairs','Efficiency %','Status'].map(h=>(
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && weekRef ? (
                <tr><td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">No data for selected week</td></tr>
              ) : rows.map((r: any, i: number) => {
                const eff = r.efficiency_pct ?? 0;
                const effStatus = eff >= 100 ? 'active' : eff >= 80 ? 'pending' : 'rejected';
                return (
                  <tr key={i} className={i%2===0?'bg-white':'bg-[#F5F4F8]'}>
                    <td className="px-4 py-3 font-medium">{r.line_name}</td>
                    <td className="px-4 py-3 text-muted-foreground">{r.factory_name}</td>
                    <td className="px-4 py-3">{r.total_pairs?.toLocaleString()}</td>
                    <td className="px-4 py-3">{r.target_pairs?.toLocaleString() ?? '—'}</td>
                    <td className="px-4 py-3">
                      <span className={`font-bold ${eff>=100?'text-green-600':eff>=80?'text-amber-600':'text-red-600'}`}>
                        {eff.toFixed(1)}%
                      </span>
                    </td>
                    <td className="px-4 py-3"><StatusBadge status={effStatus} label={eff>=100?'ON TARGET':eff>=80?'NEAR TARGET':'BELOW TARGET'}/></td>
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
