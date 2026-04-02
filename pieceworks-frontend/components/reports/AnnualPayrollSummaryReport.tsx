'use client';
import { useState } from 'react';
import { useAnnualPayrollSummaryReport } from '@/hooks/useReports';
import { Skeleton } from '@/components/ui/skeleton';
import { formatPKR } from '@/lib/formatters';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function AnnualPayrollSummaryReport() {
  const [year, setYear] = useState(new Date().getFullYear());
  const { data, isPending } = useAnnualPayrollSummaryReport({ year });
  const rows = (data as any)?.data?.weekly_runs ?? [];
  const summary = (data as any)?.data?.summary;

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <select className="border rounded-lg px-3 py-2 text-sm" value={year} onChange={e=>setYear(Number(e.target.value))}>
          {[2024,2025,2026].map(y=><option key={y} value={y}>{y}</option>)}
        </select>
        <Button variant="outline" size="sm" className="gap-1.5">
          <Download size={14} /> CSV
        </Button>
      </div>
      {summary && (
        <div className="grid grid-cols-4 gap-3">
          {[
            {label:'Total Gross',value:formatPKR(summary.total_gross??0)},
            {label:'Total Deductions',value:formatPKR(summary.total_deductions??0)},
            {label:'Total Net Paid',value:formatPKR(summary.total_net??0)},
            {label:'Total Workers',value:summary.total_workers ?? '—'},
          ].map(s=>(
            <div key={s.label} className="bg-[#322E53] text-white rounded-lg p-4">
              <p className="text-xs opacity-70 uppercase tracking-wide">{s.label}</p>
              <p className="text-xl font-bold mt-1">{s.value}</p>
            </div>
          ))}
        </div>
      )}
      {isPending ? (
        <div className="space-y-2">{Array.from({length:6}).map((_,i)=><Skeleton key={i} className="h-10"/>)}</div>
      ) : (
        <div className="rounded-lg border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-[#322E53] text-white">
              <tr>
                {['Week','Period','Total Gross','Deductions','Top-ups','Net Paid','Status'].map(h=>(
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr><td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">No payroll data for {year}</td></tr>
              ) : rows.map((r: any, i: number) => (
                <tr key={i} className={i%2===0?'bg-white':'bg-[#F5F4F8]'}>
                  <td className="px-4 py-3 font-mono text-xs">{r.week_ref}</td>
                  <td className="px-4 py-3 text-xs text-muted-foreground">{r.start_date} – {r.end_date}</td>
                  <td className="px-4 py-3">{formatPKR(r.total_gross??0)}</td>
                  <td className="px-4 py-3">{formatPKR(r.total_deductions??0)}</td>
                  <td className="px-4 py-3">{formatPKR(r.total_topups??0)}</td>
                  <td className="px-4 py-3 font-semibold">{formatPKR(r.total_net??0)}</td>
                  <td className="px-4 py-3">
                    <span className={`text-xs font-bold uppercase ${r.status==='paid'?'text-green-600':r.status==='locked'?'text-[#322E53]':'text-amber-600'}`}>
                      {r.status}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
