'use client';
import { useState } from 'react';
import { useRejectionAnalysisReport } from '@/hooks/useReports';
import { Skeleton } from '@/components/ui/skeleton';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function RejectionAnalysisReport() {
  const currentMonth = new Date().toISOString().slice(0,7);
  const [month, setMonth] = useState(currentMonth);
  const { data, isPending } = useRejectionAnalysisReport({ month: month || undefined });
  const rows = (data as any)?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <input type="month" className="border rounded-lg px-3 py-2 text-sm"
          value={month} onChange={e => setMonth(e.target.value)} />
        <Button variant="outline" size="sm" className="gap-1.5">
          <Download size={14} /> CSV
        </Button>
      </div>
      {isPending ? (
        <div className="space-y-2">{Array.from({length:6}).map((_,i)=><Skeleton key={i} className="h-10"/>)}</div>
      ) : (
        <div className="rounded-lg border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-[#322E53] text-white">
              <tr>
                {['Worker','Task','Style','Line','Pairs Rejected','Rejection Rate'].map(h=>(
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr><td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">No rejections recorded for {month}</td></tr>
              ) : rows.map((r: any, i: number) => {
                const rate = r.rejection_rate ?? 0;
                return (
                  <tr key={i} className={i%2===0?'bg-white':'bg-[#F5F4F8]'}>
                    <td className="px-4 py-3 font-medium">{r.worker_name}</td>
                    <td className="px-4 py-3">{r.task}</td>
                    <td className="px-4 py-3 font-mono text-xs">{r.style_code}</td>
                    <td className="px-4 py-3 text-muted-foreground">{r.line_name}</td>
                    <td className="px-4 py-3">{r.pairs_rejected?.toLocaleString()}</td>
                    <td className="px-4 py-3">
                      <span className={`font-bold ${rate>=10?'text-red-600':rate>=5?'text-amber-600':'text-green-600'}`}>
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
