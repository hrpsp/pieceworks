'use client';
import { useState } from 'react';
import { useGhostWorkerReport } from '@/hooks/useReports';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/pieceworks/StatusBadge';
import { Download, AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function GhostWorkerReport() {
  const [weekRef, setWeekRef] = useState('');
  const { data, isPending } = useGhostWorkerReport({ week_ref: weekRef || undefined });
  const rows = (data as any)?.data ?? [];
  const highRisk = rows.filter((r: any) => r.risk_level === 'high').length;

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <input type="week" className="border rounded-lg px-3 py-2 text-sm"
          value={weekRef} onChange={e => setWeekRef(e.target.value)} />
        <Button variant="outline" size="sm" className="gap-1.5" disabled={!weekRef}>
          <Download size={14} /> CSV
        </Button>
      </div>
      {highRisk > 0 && (
        <div className="flex items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
          <AlertTriangle size={15}/> <strong>{highRisk} high-risk</strong> ghost worker flag{highRisk>1?'s':''} detected
        </div>
      )}
      {isPending && weekRef ? (
        <div className="space-y-2">{Array.from({length:5}).map((_,i)=><Skeleton key={i} className="h-10"/>)}</div>
      ) : (
        <div className="rounded-lg border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-[#322E53] text-white">
              <tr>
                {['Worker','Contractor','Date','Biometric','Production Anomaly','Risk Level','Override By'].map(h=>(
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && weekRef ? (
                <tr><td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">No ghost worker flags for selected week</td></tr>
              ) : rows.map((r: any, i: number) => (
                <tr key={i} className={i%2===0?'bg-white':'bg-[#F5F4F8]'}>
                  <td className="px-4 py-3 font-medium">{r.worker_name}</td>
                  <td className="px-4 py-3 text-muted-foreground">{r.contractor_name}</td>
                  <td className="px-4 py-3 text-xs">{r.work_date}</td>
                  <td className="px-4 py-3">
                    {r.biometric_present ? <StatusBadge status="clean" label="PRESENT"/> : <StatusBadge status="error" label="MISSING"/>}
                  </td>
                  <td className="px-4 py-3">
                    {r.production_anomaly ? <StatusBadge status="warning" label="ANOMALY"/> : <span className="text-muted-foreground text-xs">—</span>}
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge status={r.risk_level==='high'?'error':r.risk_level==='medium'?'warning':'clean'} label={r.risk_level?.toUpperCase()}/>
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
