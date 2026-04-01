'use client';

import { useQuery }     from '@tanstack/react-query';
import { apiClient }    from '@/lib/api-client';
import { downloadFromApi } from '@/lib/download';
import { useState }     from 'react';
import { Button }       from '@/components/ui/button';
import { Skeleton }     from '@/components/ui/skeleton';
import { Badge }        from '@/components/ui/badge';
import { Download, AlertCircle, AlertTriangle, Calendar } from 'lucide-react';
import type { ApiEnvelope } from '@/lib/api-client';

// ── Types ─────────────────────────────────────────────────────────────────────

interface TenureMilestoneRow {
  worker_id:         number;
  worker_name:       string;
  contractor_name:   string;
  join_date:         string;
  milestone_days:    90 | 365 | 1095 | 1825;
  milestone_label:   string;
  days_away:         number;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const MILESTONE_COLORS: Record<number, string> = {
  90:   'bg-amber-100 text-amber-700',
  365:  'bg-blue-100 text-blue-700',
  1095: 'bg-purple-100 text-purple-700',
  1825: 'bg-brand-peach/20 text-brand-dark',
};

// ── Component ─────────────────────────────────────────────────────────────────

export function TenureMilestoneReport() {
  const [downloading, setDownloading] = useState(false);

  const query = useQuery({
    queryKey: ['report', 'tenure-milestones'],
    queryFn:  () =>
      apiClient.get<ApiEnvelope<TenureMilestoneRow[]>>('/reports/tenure-milestones'),
  });

  const rows = query.data?.data ?? [];

  // Separate IRRA threshold rows (90-day)
  const irraRows = rows.filter(r => r.milestone_days === 90);
  const otherRows = rows.filter(r => r.milestone_days !== 90);

  async function handleDownload() {
    setDownloading(true);
    try {
      await downloadFromApi('/reports/tenure-milestones', { csv: '1' }, 'tenure-milestones.csv');
    } finally {
      setDownloading(false);
    }
  }

  return (
    <div className="space-y-5">
      {/* Header actions */}
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          Workers reaching a tenure milestone within the next 30 days.
        </p>
        <Button
          variant="outline"
          size="sm"
          onClick={handleDownload}
          disabled={downloading}
          className="gap-2 border-brand-dark text-brand-dark"
        >
          <Download size={14}/>
          {downloading ? 'Downloading…' : 'Download CSV'}
        </Button>
      </div>

      {/* IRRA alert banner */}
      {irraRows.length > 0 && (
        <div className="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
          <AlertTriangle size={16} className="text-amber-600 mt-0.5 shrink-0"/>
          <div>
            <p className="text-sm font-semibold text-amber-800">
              {irraRows.length} worker{irraRows.length > 1 ? 's' : ''} approaching 90-day IRRA threshold
            </p>
            <p className="text-xs text-amber-700 mt-0.5">
              Workers employed for 90+ days may acquire additional statutory rights under IRRA.
            </p>
          </div>
        </div>
      )}

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
        <div className="flex flex-col items-center gap-2 text-sm text-muted-foreground p-8 border border-dashed rounded-xl">
          <Calendar size={28} className="text-muted-foreground/30"/>
          No upcoming milestones within the next 30 days.
        </div>
      ) : (
        <div className="rounded-xl border border-border overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/40">
                {['Worker', 'Contractor', 'Join Date', 'Milestone', 'Days Away'].map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {/* IRRA rows first (already sorted by urgency) */}
              {[...irraRows, ...otherRows]
                .sort((a, b) => a.days_away - b.days_away)
                .map(row => (
                  <tr
                    key={`${row.worker_id}-${row.milestone_days}`}
                    className={`border-b last:border-0 hover:bg-muted/20 ${
                      row.milestone_days === 90 ? 'bg-amber-50/30' : ''
                    }`}
                  >
                    <td className="px-4 py-3 font-medium text-foreground">
                      <span className="flex items-center gap-2">
                        {row.milestone_days === 90 && <AlertTriangle size={12} className="text-amber-500 shrink-0"/>}
                        {row.worker_name}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-muted-foreground text-xs">{row.contractor_name}</td>
                    <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{row.join_date}</td>
                    <td className="px-4 py-3">
                      <Badge className={`border-0 text-xs ${MILESTONE_COLORS[row.milestone_days] ?? 'bg-muted text-muted-foreground'}`}>
                        {row.milestone_label}
                      </Badge>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`font-mono font-semibold ${
                        row.days_away <= 7  ? 'text-red-600' :
                        row.days_away <= 14 ? 'text-amber-600' :
                        'text-foreground'
                      }`}>
                        {row.days_away}d
                      </span>
                    </td>
                  </tr>
                ))
              }
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
