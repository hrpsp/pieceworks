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
import { Download, AlertCircle } from 'lucide-react';
import type { ApiEnvelope } from '@/lib/api-client';

// ── Types ─────────────────────────────────────────────────────────────────────

interface ContractorPerformanceRow {
  contractor_id:     number;
  contractor_name:   string;
  delivery_score:    number;
  rejection_rate:    number;
  compliance_score:  number;
  ghost_flags:       number;
  composite_score:   number;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function currentMonthValue() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function scoreBadge(score: number) {
  if (score >= 80) return <Badge className="bg-green-100 text-green-700 border-0 font-mono">{score.toFixed(0)}</Badge>;
  if (score >= 60) return <Badge className="bg-amber-100 text-amber-700 border-0 font-mono">{score.toFixed(0)}</Badge>;
  return               <Badge className="bg-red-100 text-red-700 border-0 font-mono">{score.toFixed(0)}</Badge>;
}

function scoreColor(score: number) {
  if (score >= 80) return 'text-green-700';
  if (score >= 60) return 'text-amber-700';
  return 'text-red-700';
}

// ── Component ─────────────────────────────────────────────────────────────────

export function ContractorPerformanceReport() {
  const [monthValue,  setMonthValue]  = useState(currentMonthValue());
  const [downloading, setDownloading] = useState(false);

  const [year, month] = monthValue.split('-').map(Number);

  const query = useQuery({
    queryKey: ['report', 'contractor-performance', year, month],
    queryFn:  () =>
      apiClient.get<ApiEnvelope<ContractorPerformanceRow[]>>(
        `/reports/contractor-performance?year=${year}&month=${month}`
      ),
    enabled: !!(year && month),
  });

  const rows = query.data?.data ?? [];
  const monthLabel = new Date(year, month - 1).toLocaleString('default', { month: 'long', year: 'numeric' });

  async function handleDownload() {
    setDownloading(true);
    try {
      await downloadFromApi(
        '/reports/contractor-performance',
        { csv: '1', year: String(year), month: String(month) },
        `contractor-performance-${monthValue}.csv`
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
          <Label className="text-xs text-muted-foreground">Month</Label>
          <Input
            type="month"
            value={monthValue}
            onChange={e => setMonthValue(e.target.value)}
            className="h-9 w-44"
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

      {/* Score legend */}
      <div className="flex items-center gap-3 text-xs text-muted-foreground">
        <span className="flex items-center gap-1.5">
          <span className="w-2.5 h-2.5 rounded-sm bg-green-100 border border-green-300 inline-block"/>
          ≥ 80 — Good
        </span>
        <span className="flex items-center gap-1.5">
          <span className="w-2.5 h-2.5 rounded-sm bg-amber-100 border border-amber-300 inline-block"/>
          60–79 — Fair
        </span>
        <span className="flex items-center gap-1.5">
          <span className="w-2.5 h-2.5 rounded-sm bg-red-100 border border-red-300 inline-block"/>
          &lt; 60 — Poor
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
          No contractor data for {monthLabel}.
        </div>
      ) : (
        <div className="rounded-xl border border-border overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/40">
                {['Contractor', 'Delivery Score', 'Rejection Rate', 'Compliance', 'Ghost Flags', 'Composite'].map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows
                .slice()
                .sort((a, b) => b.composite_score - a.composite_score)
                .map(row => (
                  <tr key={row.contractor_id} className="border-b last:border-0 hover:bg-muted/20">
                    <td className="px-4 py-3 font-medium text-foreground">{row.contractor_name}</td>
                    <td className="px-4 py-3">{scoreBadge(row.delivery_score)}</td>
                    <td className={`px-4 py-3 font-mono text-sm ${row.rejection_rate > 5 ? 'text-red-600 font-semibold' : 'text-muted-foreground'}`}>
                      {row.rejection_rate.toFixed(1)}%
                    </td>
                    <td className="px-4 py-3">{scoreBadge(row.compliance_score)}</td>
                    <td className="px-4 py-3">
                      {row.ghost_flags > 0
                        ? <Badge className="bg-red-100 text-red-700 border-0">{row.ghost_flags}</Badge>
                        : <span className="text-muted-foreground/40 text-xs">—</span>
                      }
                    </td>
                    <td className={`px-4 py-3 font-bold text-base ${scoreColor(row.composite_score)}`}>
                      {row.composite_score.toFixed(0)}
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
