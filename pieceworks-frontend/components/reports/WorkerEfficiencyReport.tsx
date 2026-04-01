'use client';

import { useState }     from 'react';
import { useQuery }     from '@tanstack/react-query';
import { apiClient }    from '@/lib/api-client';
import { downloadFromApi } from '@/lib/download';
import { Button }       from '@/components/ui/button';
import { Input }        from '@/components/ui/input';
import { Label }        from '@/components/ui/label';
import { Skeleton }     from '@/components/ui/skeleton';
import { Badge }        from '@/components/ui/badge';
import { Download, AlertCircle, Trophy } from 'lucide-react';
import type { ApiEnvelope } from '@/lib/api-client';

// ── Types ─────────────────────────────────────────────────────────────────────

interface WorkerEfficiencyRow {
  worker_id:         number;
  worker_name:       string;
  contractor_name:   string;
  /** Pairs per week for the last 4 weeks, oldest first */
  weekly_pairs:      number[];
  avg_pairs:         number;
  rank:              number;
}

// ── Sparkline ─────────────────────────────────────────────────────────────────

function Sparkline({ values }: { values: number[] }) {
  if (values.length < 2) return <span className="text-muted-foreground/40 text-xs">—</span>;
  const max  = Math.max(...values);
  const min  = Math.min(...values);
  const span = max - min || 1;
  const W = 56, H = 18;
  const pts = values
    .map((v, i) => `${(i / (values.length - 1)) * W},${H - ((v - min) / span) * H}`)
    .join(' ');
  return (
    <svg width={W} height={H} className="inline-block align-middle">
      <polyline points={pts} fill="none" stroke="#E8956D" strokeWidth={1.5} strokeLinejoin="round" strokeLinecap="round"/>
    </svg>
  );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function currentWeekRef() {
  const d = new Date();
  const thursday = new Date(d);
  const day = d.getDay() || 7;
  thursday.setDate(d.getDate() + (4 - day));
  const jan4    = new Date(thursday.getFullYear(), 0, 4);
  const jan4day = jan4.getDay() || 7;
  const week1Mon = new Date(jan4);
  week1Mon.setDate(jan4.getDate() - jan4day + 1);
  const week = Math.floor((thursday.getTime() - week1Mon.getTime()) / (7 * 86400000)) + 1;
  return `${thursday.getFullYear()}-W${String(week).padStart(2, '0')}`;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function WorkerEfficiencyReport() {
  const [weekRef,       setWeekRef]       = useState(currentWeekRef());
  const [contractorFilter, setContractorFilter] = useState('');
  const [downloading, setDownloading]     = useState(false);

  const query = useQuery({
    queryKey: ['report', 'worker-efficiency', weekRef, contractorFilter],
    queryFn:  () =>
      apiClient.get<ApiEnvelope<WorkerEfficiencyRow[]>>(
        `/reports/worker-efficiency?week_ref=${weekRef}${contractorFilter ? `&contractor=${encodeURIComponent(contractorFilter)}` : ''}`
      ),
    enabled: !!weekRef,
  });

  const rows     = query.data?.data ?? [];
  const top5ids  = new Set(
    [...rows].sort((a, b) => b.avg_pairs - a.avg_pairs).slice(0, 5).map(r => r.worker_id)
  );

  async function handleDownload() {
    setDownloading(true);
    try {
      await downloadFromApi(
        '/reports/worker-efficiency',
        { csv: '1', week_ref: weekRef, ...(contractorFilter ? { contractor: contractorFilter } : {}) },
        `worker-efficiency-${weekRef}.csv`
      );
    } finally {
      setDownloading(false);
    }
  }

  return (
    <div className="space-y-5">
      {/* Filters */}
      <div className="flex flex-wrap items-end gap-4">
        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">Week</Label>
          <Input
            type="week"
            value={weekRef}
            onChange={e => setWeekRef(e.target.value)}
            className="h-9 w-44"
          />
        </div>
        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">Contractor</Label>
          <Input
            placeholder="Filter by contractor…"
            value={contractorFilter}
            onChange={e => setContractorFilter(e.target.value)}
            className="h-9 w-48"
          />
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={handleDownload}
          disabled={downloading}
          className="gap-2 border-brand-dark text-brand-dark ml-auto"
        >
          <Download size={14}/>
          {downloading ? 'Downloading…' : 'Download CSV'}
        </Button>
      </div>

      {/* Legend */}
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <Trophy size={12} className="text-brand-peach"/>
        <span>Top 5 performers highlighted</span>
        <span className="mx-2 text-border">·</span>
        <span>Sparkline = last 4 weeks trend</span>
      </div>

      {/* Table */}
      {query.isPending ? (
        <div className="space-y-2">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-10 rounded-lg"/>
          ))}
        </div>
      ) : query.isError ? (
        <div className="flex items-center gap-2 text-sm text-muted-foreground p-6 border border-dashed rounded-xl">
          <AlertCircle size={16}/>
          Failed to load report. API may not be available yet.
        </div>
      ) : rows.length === 0 ? (
        <div className="text-sm text-muted-foreground p-6 border border-dashed rounded-xl text-center">
          No data for {weekRef}.
        </div>
      ) : (
        <div className="rounded-xl border border-border overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/40">
                {['#', 'Worker', 'Contractor', 'Last 4 Weeks', 'Avg Pairs / Week'].map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, i) => {
                const isTop = top5ids.has(row.worker_id);
                return (
                  <tr
                    key={row.worker_id}
                    className={`border-b last:border-0 transition-colors ${
                      isTop ? 'bg-brand-peach/10 border-brand-peach/20' : 'hover:bg-muted/20'
                    }`}
                  >
                    <td className="px-4 py-3 text-xs text-muted-foreground font-mono w-10">
                      {isTop ? <Trophy size={13} className="text-brand-peach"/> : i + 1}
                    </td>
                    <td className="px-4 py-3 font-medium text-foreground">{row.worker_name}</td>
                    <td className="px-4 py-3 text-muted-foreground text-xs">{row.contractor_name}</td>
                    <td className="px-4 py-3">
                      <Sparkline values={row.weekly_pairs ?? []}/>
                    </td>
                    <td className="px-4 py-3 font-mono font-semibold text-foreground text-right">
                      {Math.round(row.avg_pairs).toLocaleString()}
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
