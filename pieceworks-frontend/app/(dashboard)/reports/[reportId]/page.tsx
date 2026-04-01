'use client';

import Link             from 'next/link';
import { Button }       from '@/components/ui/button';
import { ChevronLeft }  from 'lucide-react';
import {
  Factory, TrendingUp, ShieldCheck, Building2, Users, Award,
} from 'lucide-react';

import { DailyProductionReport }     from '@/components/reports/DailyProductionReport';
import { WorkerEfficiencyReport }    from '@/components/reports/WorkerEfficiencyReport';
import { MinWageComplianceReport }   from '@/components/reports/MinWageComplianceReport';
import { EOBIChallaniReport }        from '@/components/reports/EOBIChallaniReport';
import { ContractorPerformanceReport } from '@/components/reports/ContractorPerformanceReport';
import { TenureMilestoneReport }     from '@/components/reports/TenureMilestoneReport';

// ── Report registry ───────────────────────────────────────────────────────────

interface ReportConfig {
  title:       string;
  description: string;
  icon:        React.ElementType;
  component:   React.ComponentType;
}

const REGISTRY: Record<string, ReportConfig> = {
  'daily-production': {
    title:       'Daily Production',
    description: 'Pairs produced vs target by line and shift',
    icon:        Factory,
    component:   DailyProductionReport,
  },
  'worker-efficiency': {
    title:       'Worker Efficiency',
    description: '4-week trend with top performer highlights',
    icon:        TrendingUp,
    component:   WorkerEfficiencyReport,
  },
  'min-wage-compliance': {
    title:       'Min Wage Compliance',
    description: 'Workers below provincial minimum wage with top-up breakdown',
    icon:        ShieldCheck,
    component:   MinWageComplianceReport,
  },
  'eobi-challan': {
    title:       'EOBI Challan',
    description: 'Monthly EOBI contributions challan',
    icon:        Building2,
    component:   EOBIChallaniReport,
  },
  'contractor-performance': {
    title:       'Contractor Performance',
    description: 'Delivery, compliance, and composite scoring by contractor',
    icon:        Users,
    component:   ContractorPerformanceReport,
  },
  'tenure-milestones': {
    title:       'Tenure Milestones',
    description: 'Workers reaching IRRA or long-service thresholds within 30 days',
    icon:        Award,
    component:   TenureMilestoneReport,
  },
};

// ── Page ──────────────────────────────────────────────────────────────────────

interface Props {
  params: { reportId: string };
}

export default function ReportViewerPage({ params }: Props) {
  const { reportId } = params;
  const config = REGISTRY[reportId];

  if (!config) {
    return (
      <div className="p-6 max-w-7xl mx-auto space-y-4">
        <BackButton/>
        <div className="text-center py-16 text-muted-foreground">
          <p className="text-lg font-medium">Report not found</p>
          <p className="text-sm mt-1">No report with ID <code className="font-mono bg-muted px-1 py-0.5 rounded">{reportId}</code>.</p>
        </div>
      </div>
    );
  }

  const Icon = config.icon;
  const Report = config.component;

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <BackButton/>
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-brand-dark flex items-center justify-center">
            <Icon size={16} className="text-white"/>
          </div>
          <div>
            <h1 className="text-xl font-bold text-foreground leading-tight">{config.title}</h1>
            <p className="text-xs text-muted-foreground">{config.description}</p>
          </div>
        </div>
      </div>

      {/* Report content */}
      <div className="bg-card border border-border rounded-xl p-6">
        <Report/>
      </div>
    </div>
  );
}

// ── Back button ───────────────────────────────────────────────────────────────

function BackButton() {
  return (
    <Link href="/reports">
      <Button variant="ghost" size="sm" className="gap-1.5 text-muted-foreground hover:text-foreground -ml-2">
        <ChevronLeft size={15}/>
        Reports
      </Button>
    </Link>
  );
}
