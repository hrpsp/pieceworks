'use client';

import Link             from 'next/link';
import { Button }       from '@/components/ui/button';
import { ChevronLeft }  from 'lucide-react';
import {
  Factory, TrendingUp, ShieldCheck, Building2, Users, Award,
  DollarSign, BarChart2, Clock, AlertTriangle, XCircle, FileText,
} from 'lucide-react';

import { DailyProductionReport }        from '@/components/reports/DailyProductionReport';
import { WorkerEfficiencyReport }       from '@/components/reports/WorkerEfficiencyReport';
import { MinWageComplianceReport }      from '@/components/reports/MinWageComplianceReport';
import { EOBIChallaniReport }           from '@/components/reports/EOBIChallaniReport';
import { ContractorPerformanceReport }  from '@/components/reports/ContractorPerformanceReport';
import { TenureMilestoneReport }        from '@/components/reports/TenureMilestoneReport';
import { WageCostPerPairReport }        from '@/components/reports/WageCostPerPairReport';
import { LineProductivityReport }       from '@/components/reports/LineProductivityReport';
import { ShiftAdjustmentsReport }       from '@/components/reports/ShiftAdjustmentsReport';
import { GhostWorkerReport }            from '@/components/reports/GhostWorkerReport';
import { RejectionAnalysisReport }      from '@/components/reports/RejectionAnalysisReport';
import { AnnualPayrollSummaryReport }   from '@/components/reports/AnnualPayrollSummaryReport';

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
  'wage-cost-per-pair': {
    title:       'Wage Cost Per Pair',
    description: 'Total wage cost breakdown per pair produced by line, contractor, and task',
    icon:        DollarSign,
    component:   WageCostPerPairReport,
  },
  'line-productivity': {
    title:       'Line Productivity',
    description: 'Output, efficiency, and capacity utilisation per production line',
    icon:        BarChart2,
    component:   LineProductivityReport,
  },
  'shift-adjustments': {
    title:       'Shift Adjustments',
    description: 'Confirmed and pending shift changes with overtime flags and authorisation status',
    icon:        Clock,
    component:   ShiftAdjustmentsReport,
  },
  'ghost-worker': {
    title:       'Ghost Worker Report',
    description: 'Production anomaly and biometric mismatch flags for fraud risk assessment',
    icon:        AlertTriangle,
    component:   GhostWorkerReport,
  },
  'rejection-analysis': {
    title:       'Rejection Analysis',
    description: 'QC rejection rates by worker, line, and defect type with penalty breakdown',
    icon:        XCircle,
    component:   RejectionAnalysisReport,
  },
  'annual-payroll-summary': {
    title:       'Annual Payroll Summary',
    description: 'Full-year gross earnings, deductions, and net pay aggregates by worker and contractor',
    icon:        FileText,
    component:   AnnualPayrollSummaryReport,
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
