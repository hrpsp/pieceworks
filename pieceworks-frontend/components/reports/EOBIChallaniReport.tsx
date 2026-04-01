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
import { FileText, AlertCircle } from 'lucide-react';
import type { ApiEnvelope } from '@/lib/api-client';

// ── Types ─────────────────────────────────────────────────────────────────────

interface EOBIRow {
  worker_id:          number;
  worker_name:        string;
  eobi_no:            string;
  employee_contrib:   number;
  employer_contrib:   number;
}

interface EOBIResponse {
  rows:                   EOBIRow[];
  total_employee_contrib: number;
  total_employer_contrib: number;
  total_contrib:          number;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function currentMonthValue() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function EOBIChallaniReport() {
  const [monthValue,  setMonthValue]  = useState(currentMonthValue());
  const [pdfLoading,  setPdfLoading]  = useState(false);

  const [year, month] = monthValue.split('-').map(Number);

  const query = useQuery({
    queryKey: ['report', 'eobi-challan', year, month],
    queryFn:  () =>
      apiClient.get<ApiEnvelope<EOBIResponse>>(
        `/reports/eobi-challan?year=${year}&month=${month}`
      ),
    enabled: !!(year && month),
  });

  const rows  = query.data?.data?.rows ?? [];
  const total = query.data?.data;

  async function handlePdf() {
    setPdfLoading(true);
    try {
      await downloadFromApi(
        '/reports/eobi-challan',
        { pdf: '1', year: String(year), month: String(month) },
        `eobi-challan-${year}-${String(month).padStart(2, '0')}.pdf`
      );
    } finally {
      setPdfLoading(false);
    }
  }

  const monthLabel = new Date(year, month - 1).toLocaleString('default', { month: 'long', year: 'numeric' });

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
          size="sm"
          onClick={handlePdf}
          disabled={pdfLoading}
          className="gap-2 bg-brand-dark hover:bg-brand-mid text-white ml-auto"
        >
          <FileText size={14}/>
          {pdfLoading ? 'Generating…' : 'Download Challan PDF'}
        </Button>
      </div>

      {/* Table */}
      {query.isPending ? (
        <div className="space-y-2">
          {Array.from({ length: 10 }).map((_, i) => (
            <Skeleton key={i} className="h-10 rounded-lg"/>
          ))}
        </div>
      ) : query.isError ? (
        <div className="flex items-center gap-2 text-sm text-muted-foreground p-6 border border-dashed rounded-xl">
          <AlertCircle size={16}/>
          Failed to load EOBI data. API may not be available yet.
        </div>
      ) : rows.length === 0 ? (
        <div className="text-sm text-muted-foreground p-6 border border-dashed rounded-xl text-center">
          No EOBI entries for {monthLabel}.
        </div>
      ) : (
        <div className="rounded-xl border border-border overflow-hidden">
          <div className="px-5 py-3 border-b border-border bg-muted/40">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              EOBI Challan — {monthLabel}
            </p>
          </div>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/20">
                {['Worker', 'EOBI No.', 'Employee Contrib.', 'Employer Contrib.', 'Total'].map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map(row => (
                <tr key={row.worker_id} className="border-b last:border-0 hover:bg-muted/20">
                  <td className="px-4 py-3 font-medium text-foreground">{row.worker_name}</td>
                  <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{row.eobi_no || '—'}</td>
                  <td className="px-4 py-3 font-mono text-right">{formatPKR(row.employee_contrib)}</td>
                  <td className="px-4 py-3 font-mono text-right">{formatPKR(row.employer_contrib)}</td>
                  <td className="px-4 py-3 font-mono text-right font-semibold">
                    {formatPKR(row.employee_contrib + row.employer_contrib)}
                  </td>
                </tr>
              ))}
            </tbody>
            {total && (
              <tfoot>
                <tr className="border-t-2 border-border bg-muted/40">
                  <td colSpan={2} className="px-4 py-3 font-bold text-foreground text-sm">Total</td>
                  <td className="px-4 py-3 font-mono font-bold text-right text-foreground">
                    {formatPKR(total.total_employee_contrib)}
                  </td>
                  <td className="px-4 py-3 font-mono font-bold text-right text-foreground">
                    {formatPKR(total.total_employer_contrib)}
                  </td>
                  <td className="px-4 py-3 font-mono font-bold text-right text-brand-dark">
                    {formatPKR(total.total_contrib)}
                  </td>
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      )}
    </div>
  );
}
