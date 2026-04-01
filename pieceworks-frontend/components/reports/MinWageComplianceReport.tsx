'use client';

import { useState }     from 'react';
import { useQuery }     from '@tanstack/react-query';
import { apiClient }    from '@/lib/api-client';
import { downloadFromApi } from '@/lib/download';
import { formatPKR }    from '@/lib/formatters';
import { Button }       from '@/components/ui/button';
import { Input }        from '@/components/ui/input';
import { Label }        from '@/components/ui/label';
import { Skeleton }     from '@/components/ui/skeleton';
import { Badge }        from '@/components/ui/badge';
import { Download, AlertCircle, ShieldCheck, FileText } from 'lucide-react';
import type { ApiEnvelope } from '@/lib/api-client';

// ── Types ─────────────────────────────────────────────────────────────────────

interface MinWageRow {
  worker_id:       number;
  worker_name:     string;
  contractor_name: string;
  gross_earnings:  number;
  top_up_amount:   number;
  net_pay:         number;
}

interface MinWageSummary {
  total_workers:        number;
  workers_topped_up:    number;
  total_top_up_amount:  number;
  contractors_affected: number;
}

interface MinWageResponse {
  summary: MinWageSummary;
  rows:    MinWageRow[];
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

export function MinWageComplianceReport() {
  const [weekRef,     setWeekRef]     = useState(currentWeekRef());
  const [downloading, setDownloading] = useState(false);
  const [pdfLoading,  setPdfLoading]  = useState(false);

  const query = useQuery({
    queryKey: ['report', 'min-wage-compliance', weekRef],
    queryFn:  () =>
      apiClient.get<ApiEnvelope<MinWageResponse>>(
        `/reports/min-wage-compliance?week_ref=${weekRef}`
      ),
    enabled: !!weekRef,
  });

  const summary = query.data?.data?.summary;
  const rows    = query.data?.data?.rows ?? [];

  async function handleCsv() {
    setDownloading(true);
    try {
      await downloadFromApi('/reports/min-wage-compliance', { csv: '1', week_ref: weekRef }, `min-wage-compliance-${weekRef}.csv`);
    } finally {
      setDownloading(false);
    }
  }

  async function handlePdf() {
    setPdfLoading(true);
    try {
      await downloadFromApi('/reports/min-wage-compliance', { pdf: '1', week_ref: weekRef }, `min-wage-compliance-${weekRef}.pdf`);
    } finally {
      setPdfLoading(false);
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
        <div className="flex items-center gap-2 ml-auto">
          <Button
            variant="outline"
            size="sm"
            onClick={handleCsv}
            disabled={downloading}
            className="gap-2 border-brand-dark text-brand-dark"
          >
            <Download size={14}/>
            {downloading ? 'Downloading…' : 'CSV'}
          </Button>
          <Button
            size="sm"
            onClick={handlePdf}
            disabled={pdfLoading}
            className="gap-2 bg-brand-dark hover:bg-brand-mid text-white"
          >
            <FileText size={14}/>
            {pdfLoading ? 'Generating…' : 'PDF (Legal)'}
          </Button>
        </div>
      </div>

      {/* Summary stats */}
      {query.isPending ? (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-20 rounded-xl"/>)}
        </div>
      ) : summary && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <StatCard label="Total Workers"        value={summary.total_workers} />
          <StatCard label="Workers Topped Up"    value={summary.workers_topped_up}
            accent={summary.workers_topped_up > 0} />
          <StatCard label="Total Top-Up"         value={formatPKR(summary.total_top_up_amount)} accent={summary.total_top_up_amount > 0}/>
          <StatCard label="Contractors Affected" value={summary.contractors_affected} />
        </div>
      )}

      {/* Table */}
      {query.isError ? (
        <div className="flex items-center gap-2 text-sm text-muted-foreground p-6 border border-dashed rounded-xl">
          <AlertCircle size={16}/>
          Failed to load report. API may not be available yet.
        </div>
      ) : !query.isPending && rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 text-sm text-muted-foreground p-8 border border-dashed rounded-xl">
          <ShieldCheck size={28} className="text-green-500"/>
          All workers are above minimum wage for {weekRef}.
        </div>
      ) : !query.isPending && (
        <div className="rounded-xl border border-border overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/40">
                {['Worker', 'Contractor', 'Gross Earnings', 'Top-Up', 'Net Pay'].map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map(row => (
                <tr
                  key={row.worker_id}
                  className={`border-b last:border-0 hover:bg-muted/20 ${
                    row.top_up_amount > 0 ? 'bg-amber-50/40' : ''
                  }`}
                >
                  <td className="px-4 py-3 font-medium text-foreground">{row.worker_name}</td>
                  <td className="px-4 py-3 text-muted-foreground text-xs">{row.contractor_name}</td>
                  <td className="px-4 py-3 font-mono text-right">{formatPKR(row.gross_earnings)}</td>
                  <td className="px-4 py-3 font-mono text-right">
                    {row.top_up_amount > 0
                      ? <Badge className="bg-amber-100 text-amber-700 border-0">{formatPKR(row.top_up_amount)}</Badge>
                      : <span className="text-muted-foreground/40 text-xs">—</span>
                    }
                  </td>
                  <td className="px-4 py-3 font-mono font-semibold text-right text-foreground">{formatPKR(row.net_pay)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

// ── Stat card ─────────────────────────────────────────────────────────────────

function StatCard({ label, value, accent }: { label: string; value: string | number; accent?: boolean }) {
  return (
    <div className={`rounded-xl border p-4 ${accent ? 'border-amber-200 bg-amber-50/50' : 'border-border bg-card'}`}>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={`text-xl font-bold mt-1 ${accent ? 'text-amber-700' : 'text-foreground'}`}>{value}</p>
    </div>
  );
}
