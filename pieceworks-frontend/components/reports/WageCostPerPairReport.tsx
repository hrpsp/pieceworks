'use client';
import { useState } from 'react';
import { useWageCostPerPairReport } from '@/hooks/useReports';
import { Skeleton } from '@/components/ui/skeleton';
import { formatPKR } from '@/lib/formatters';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function WageCostPerPairReport() {
  const [weekRef, setWeekRef] = useState('');
  const { data, isPending } = useWageCostPerPairReport({ week_ref: weekRef || undefined });
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
                {['Style SKU','Style Name','Tier','Total Pairs','Total Wage Cost','Wage / Pair'].map(h=>(
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && weekRef ? (
                <tr><td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">No data for selected week</td></tr>
              ) : rows.map((r: any, i: number) => (
                <tr key={i} className={i%2===0?'bg-white':'bg-[#F5F4F8]'}>
                  <td className="px-4 py-3 font-mono text-xs">{r.style_code}</td>
                  <td className="px-4 py-3">{r.style_name}</td>
                  <td className="px-4 py-3 capitalize">{r.complexity_tier}</td>
                  <td className="px-4 py-3">{r.total_pairs?.toLocaleString()}</td>
                  <td className="px-4 py-3">{formatPKR(r.total_wage_cost ?? 0)}</td>
                  <td className="px-4 py-3 font-semibold">PKR {r.wage_per_pair ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
