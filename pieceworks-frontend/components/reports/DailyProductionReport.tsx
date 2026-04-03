'use client';

import { useState }     from 'react';
import { useQuery }     from '@tanstack/react-query';
import { apiClient }    from '@/lib/api-client';
import { downloadFromApi } from '@/lib/download';
import { Button }       from '@/components/ui/button';
import { Input }        from '@/components/ui/input';
import { Label }        from '@/components/ui/label';
import { Skeleton }     from '@/components/ui/skeleton';
import { Download, AlertCircle } from 'lucide-react';
import type { ApiEnvelope } from '@/lib/api-client';

// ── Types ─────────────────────────────────────────────────────────────────────

interface DailyProductionRow {
  worker_id:    number;
  worker_name:  string;
  cnic:         string | null;
  line:         string;
  contractor:   string | null;
  style_sku:    string | null;
  tier:         string | null;
  pieces:       number;
  rate:         number;
  earnings:     number;
  work_date:    string;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function todayStr() {
  return new Date().toISOString().slice(0, 10);
}

// ── Component ─────────────────────────────────────────────────────────────────

export function DailyProductionReport() {
  const [date,       setDate]       = useState(todayStr());
  const [lineFilter, setLineFilter] = useState('');
  const [downloading, setDownloading] = useState(false);

  const query = useQuery({
    queryKey: ['report', 'daily-production', date, lineFilter],
    queryFn:  () =>
      apiClient.get<ApiEnvelope<DailyProductionRow[]>>(
        `/reports/daily-production?date=${date}${lineFilter ? `&line=${encodeURIComponent(lineFilter)}` : ''}`
      ),
    enabled: !!date,
  });

  const rows = query.data?.data ?? [];

  async function handleDownload() {
    setDownloading(true);
    try {
      await downloadFromApi(
        '/reports/daily-production',
        { csv: '1', date, ...(lineFilter ? { line: lineFilter } : {}) },
        `daily-production-${date}.csv`
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
          <Label className="text-xs text-muted-foreground">Date</Label>
          <Input
            type="date"
            value={date}
            onChange={e => setDate(e.target.value)}
            className="h-9 w-44"
          />
        </div>
        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">Production Line</Label>
          <Input
            placeholder="e.g. Line A"
            value={lineFilter}
            onChange={e => setLineFilter(e.target.value)}
            className="h-9 w-40"
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
      <div className="flex items-center gap-3 text-xs text-muted-foreground">
        <span className="flex items-center gap-1.5">
          <span className="w-2.5 h-2.5 rounded-sm bg-green-100 border border-green-300 inline-block"/>
          ≥ 100% target
        </span>
        <span className="flex items-center gap-1.5">
          <span className="w-2.5 h-2.5 rounded-sm bg-amber-100 border border-amber-300 inline-block"/>
          80–99%
        </span>
        <span className="flex items-center gap-1.5">
          <span className="w-2.5 h-2.5 rounded-sm bg-red-100 border border-red-300 inline-block"/>
          &lt; 80%
        </span>
      </div>

      {/* Table */}
      {query.isPending ? (
        <div className="space-y-2">
          {Array.from({ length: 6 }).map((_, i) => (
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
          No production data for {date}.
        </div>
      ) : (
        <div className="rounded-xl border border-border overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/40">
                {['Worker', 'Line', 'Contractor', 'Pieces', 'Rate (₨)', 'Earnings (₨)'].map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, i) => (
                <tr key={i} className="border-b last:border-0 hover:bg-muted/20">
                  <td className="px-4 py-3 font-medium text-foreground">{row.worker_name ?? '—'}</td>
                  <td className="px-4 py-3 text-muted-foreground">{row.line ?? '—'}</td>
                  <td className="px-4 py-3 text-muted-foreground text-xs">{row.contractor ?? '—'}</td>
                  <td className="px-4 py-3 font-mono text-right">{(row.pieces ?? 0).toLocaleString()}</td>
                  <td className="px-4 py-3 font-mono text-right text-muted-foreground">{(row.rate ?? 0).toLocaleString()}</td>
                  <td className="px-4 py-3 font-mono text-right font-semibold">{(row.earnings ?? 0).toLocaleString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
