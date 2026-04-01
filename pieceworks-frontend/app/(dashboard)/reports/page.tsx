'use client';

import Link             from 'next/link';
import { useState }     from 'react';
import { downloadFromApi } from '@/lib/download';
import { Button }       from '@/components/ui/button';
import { Badge }        from '@/components/ui/badge';
import {
  Factory, TrendingUp, ShieldCheck, Building2, Users, Award,
  Download, ExternalLink, Loader2,
} from 'lucide-react';

// ── Report catalog ────────────────────────────────────────────────────────────

interface ReportMeta {
  id:          string;
  title:       string;
  description: string;
  filterTags:  string[];
  icon:        React.ElementType;
  csvParams:   Record<string, string>;
  csvFilename: string;
  accentColor: string;
}

function todayStr()        { return new Date().toISOString().slice(0, 10); }
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
function currentMonthParts() {
  const d = new Date();
  return { year: String(d.getFullYear()), month: String(d.getMonth() + 1) };
}

const REPORTS: ReportMeta[] = [
  {
    id:          'daily-production',
    title:       'Daily Production',
    description: 'Pairs produced vs target by production line and shift. Color-coded efficiency indicators.',
    filterTags:  ['Date', 'Line'],
    icon:        Factory,
    csvParams:   { csv: '1', date: todayStr() },
    csvFilename: `daily-production-${todayStr()}.csv`,
    accentColor: 'bg-blue-50 text-blue-700 border-blue-100',
  },
  {
    id:          'worker-efficiency',
    title:       'Worker Efficiency',
    description: '4-week sparkline trend per worker. Top 5 performers highlighted.',
    filterTags:  ['Week', 'Contractor'],
    icon:        TrendingUp,
    csvParams:   { csv: '1', week_ref: currentWeekRef() },
    csvFilename: `worker-efficiency-${currentWeekRef()}.csv`,
    accentColor: 'bg-brand-peach/10 text-brand-dark border-brand-peach/20',
  },
  {
    id:          'min-wage-compliance',
    title:       'Min Wage Compliance',
    description: 'Workers below provincial minimum wage with top-up amounts. Includes PDF for legal records.',
    filterTags:  ['Week'],
    icon:        ShieldCheck,
    csvParams:   { csv: '1', week_ref: currentWeekRef() },
    csvFilename: `min-wage-compliance-${currentWeekRef()}.csv`,
    accentColor: 'bg-green-50 text-green-700 border-green-100',
  },
  {
    id:          'eobi-challan',
    title:       'EOBI Challan',
    description: 'Monthly employee & employer EOBI contributions with challan PDF for submission.',
    filterTags:  ['Month', 'Year'],
    icon:        Building2,
    csvParams:   { pdf: '1', ...currentMonthParts() },
    csvFilename: `eobi-challan-${currentMonthParts().year}-${currentMonthParts().month.padStart(2, '0')}.pdf`,
    accentColor: 'bg-purple-50 text-purple-700 border-purple-100',
  },
  {
    id:          'contractor-performance',
    title:       'Contractor Performance',
    description: 'Composite scoring: delivery, rejections, compliance, and ghost flags per contractor.',
    filterTags:  ['Month'],
    icon:        Users,
    csvParams:   { csv: '1', ...currentMonthParts() },
    csvFilename: `contractor-performance-${currentMonthParts().year}-${currentMonthParts().month}.csv`,
    accentColor: 'bg-amber-50 text-amber-700 border-amber-100',
  },
  {
    id:          'tenure-milestones',
    title:       'Tenure Milestones',
    description: 'Workers reaching IRRA threshold (90 days) or long-service milestones within 30 days.',
    filterTags:  ['Auto (next 30 days)'],
    icon:        Award,
    csvParams:   { csv: '1' },
    csvFilename: 'tenure-milestones.csv',
    accentColor: 'bg-rose-50 text-rose-700 border-rose-100',
  },
];

// ── Page ──────────────────────────────────────────────────────────────────────

export default function ReportsPage() {
  return (
    <div className="p-6 space-y-6 max-w-7xl mx-auto">
      <div>
        <h1 className="text-2xl font-bold text-foreground">Reports</h1>
        <p className="text-sm text-muted-foreground mt-0.5">
          Production, payroll, and compliance reports for PieceWorks HRMS.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        {REPORTS.map(report => (
          <ReportCard key={report.id} report={report}/>
        ))}
      </div>
    </div>
  );
}

// ── Report card ───────────────────────────────────────────────────────────────

function ReportCard({ report }: { report: ReportMeta }) {
  const [downloading, setDownloading] = useState(false);
  const Icon = report.icon;

  async function handleQuickDownload() {
    setDownloading(true);
    try {
      await downloadFromApi(`/reports/${report.id}`, report.csvParams, report.csvFilename);
    } catch {
      // API may not be built yet — silently fail in dev
    } finally {
      setDownloading(false);
    }
  }

  return (
    <div className="bg-card border border-border rounded-xl p-5 flex flex-col gap-4 hover:shadow-sm transition-shadow">
      {/* Icon + title */}
      <div className="flex items-start gap-3">
        <div className={`w-10 h-10 rounded-xl border flex items-center justify-center shrink-0 ${report.accentColor}`}>
          <Icon size={18}/>
        </div>
        <div className="min-w-0">
          <h2 className="font-semibold text-foreground text-sm leading-tight">{report.title}</h2>
          <p className="text-xs text-muted-foreground mt-1 leading-relaxed">{report.description}</p>
        </div>
      </div>

      {/* Filter tags */}
      <div className="flex flex-wrap gap-1.5">
        {report.filterTags.map(tag => (
          <Badge key={tag} className="bg-muted text-muted-foreground border-0 text-xs font-normal">
            {tag}
          </Badge>
        ))}
      </div>

      {/* Actions */}
      <div className="flex items-center gap-2 mt-auto pt-1">
        <Link href={`/reports/${report.id}`} className="flex-1">
          <Button size="sm" className="w-full gap-2 bg-brand-dark hover:bg-brand-mid text-white">
            <ExternalLink size={13}/>
            View Report
          </Button>
        </Link>
        <Button
          variant="outline"
          size="sm"
          onClick={handleQuickDownload}
          disabled={downloading}
          className="gap-2 border-brand-dark/30 text-brand-dark"
        >
          {downloading ? <Loader2 size={13} className="animate-spin"/> : <Download size={13}/>}
          {downloading ? '' : 'CSV'}
        </Button>
      </div>
    </div>
  );
}
