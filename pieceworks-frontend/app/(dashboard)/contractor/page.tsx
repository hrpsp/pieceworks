'use client';

import { useState }      from 'react';
import { useQuery }      from '@tanstack/react-query';
import { apiClient }     from '@/lib/api-client';
import {
  useContractorDashboard,
  useContractorSettlement,
  type ContractorWorkerBreakdown,
  type ContractorSettlementLine,
} from '@/hooks/useContractor';
import { formatPKR }     from '@/lib/formatters';
import { Skeleton }      from '@/components/ui/skeleton';
import { Badge }         from '@/components/ui/badge';
import { Building2, Users, TrendingUp, AlertCircle, Wallet } from 'lucide-react';
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from 'recharts';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Contractor {
  id:             number;
  name:           string;
  contact_person: string;
  phone:          string | null;
  payment_cycle:  string;
  status:         'active' | 'inactive' | 'blacklisted';
  contract_start: string;
  contract_end:   string | null;
}

interface ContractorListResponse {
  data: Contractor[];
  meta: { total: number };
}

// ── Donut chart ───────────────────────────────────────────────────────────────

const SLICES = ['#322E53', '#49426E', '#EEC293', '#F3AB9D'];

function WorkerDonut({ active, inactive }: { active: number; inactive: number }) {
  const data = [
    { name: 'Active',   value: active   },
    { name: 'Inactive', value: inactive },
  ].filter(d => d.value > 0);

  if (!data.length) {
    return (
      <div className="h-28 flex items-center justify-center text-muted-foreground text-xs">
        No workers
      </div>
    );
  }

  return (
    <ResponsiveContainer width="100%" height={110}>
      <PieChart>
        <Pie
          data={data}
          cx="50%" cy="50%"
          innerRadius={28} outerRadius={44}
          dataKey="value" paddingAngle={2}
        >
          {data.map((_, i) => <Cell key={i} fill={SLICES[i % SLICES.length]}/>)}
        </Pie>
        <Tooltip
          formatter={(v) => [v, '']}
          contentStyle={{ fontSize: 11, borderRadius: 6 }}
        />
      </PieChart>
    </ResponsiveContainer>
  );
}

// ── Settlement donut ──────────────────────────────────────────────────────────

function SettlementDonut({ lines }: { lines: ContractorSettlementLine[] }) {
  const data = lines
    .filter(l => l.net_settlement > 0)
    .map(l => ({ name: l.contractor_name, value: Math.round(l.net_settlement) }));

  if (!data.length) {
    return (
      <div className="h-36 flex items-center justify-center text-muted-foreground text-xs">
        No settlement data
      </div>
    );
  }

  return (
    <ResponsiveContainer width="100%" height={140}>
      <PieChart>
        <Pie
          data={data}
          cx="50%" cy="50%"
          innerRadius={35} outerRadius={55}
          dataKey="value" paddingAngle={2}
        >
          {data.map((_, i) => <Cell key={i} fill={SLICES[i % SLICES.length]}/>)}
        </Pie>
        <Tooltip
          formatter={(v) => [formatPKR(Number(v)), '']}
          contentStyle={{ fontSize: 11, borderRadius: 6 }}
        />
      </PieChart>
    </ResponsiveContainer>
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

const STATUS_COLOR: Record<string, string> = {
  active:      'bg-green-100 text-green-700',
  inactive:    'bg-amber-100 text-amber-700',
  blacklisted: 'bg-red-100 text-red-700',
};

// ── Page ──────────────────────────────────────────────────────────────────────

export default function ContractorPage() {
  const [weekRef, setWeekRef] = useState(currentWeekRef());

  const contractors  = useQuery({
    queryKey: ['contractors'],
    queryFn:  () => apiClient.get<ContractorListResponse>('/contractors'),
  });
  const dashboard    = useContractorDashboard();
  const settlement   = useContractorSettlement(weekRef);

  const contractorList = contractors.data?.data ?? [];
  const dash           = dashboard.data?.data;
  const settlementData = settlement.data?.data;

  const breakdownMap = new Map<number, ContractorWorkerBreakdown>(
    (dash?.breakdown ?? []).map(b => [b.contractor_id, b])
  );

  return (
    <div className="p-6 space-y-6 max-w-7xl mx-auto">

      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-foreground">Contractors</h1>
        <p className="text-sm text-muted-foreground mt-0.5">
          Settlement overview and workforce distribution
        </p>
      </div>

      {/* Summary row */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <SummaryCard
          icon={<Building2 size={18} className="text-brand-dark"/>}
          bg="bg-brand-dark/10"
          label="Total Contractors"
          value={dashboard.isPending ? '…' : String(dash?.total_contractors ?? contractorList.length)}
        />
        <SummaryCard
          icon={<Users size={18} className="text-green-600"/>}
          bg="bg-green-50"
          label="Active Contractors"
          value={dashboard.isPending ? '…' : String(dash?.active_contractors ?? contractorList.filter(c => c.status === 'active').length)}
        />
        <SummaryCard
          icon={<TrendingUp size={18} className="text-brand-dark"/>}
          bg="bg-brand-peach/20"
          label="Total Workers"
          value={dashboard.isPending ? '…' : String(dash?.total_workers ?? '—')}
        />
        <SummaryCard
          icon={<Wallet size={18} className="text-purple-600"/>}
          bg="bg-purple-50"
          label="This Week Settlement"
          value={settlementData ? formatPKR(settlementData.total_net) : '…'}
        />
      </div>

      {/* Contractor grid */}
      {contractors.isPending ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-56 rounded-xl"/>
          ))}
        </div>
      ) : contractors.isError ? (
        <div className="bg-card rounded-xl border border-border p-12 text-center space-y-3">
          <AlertCircle size={32} className="mx-auto text-muted-foreground/40"/>
          <p className="text-muted-foreground text-sm">
            Could not load contractors.{' '}
            <code className="bg-muted px-1.5 py-0.5 rounded text-xs">GET /contractors</code> endpoint needs to be built.
          </p>
        </div>
      ) : contractorList.length === 0 ? (
        <div className="bg-card rounded-xl border border-border p-12 text-center space-y-3">
          <Building2 size={32} className="mx-auto text-muted-foreground/40"/>
          <p className="text-muted-foreground text-sm">No contractors found.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {contractorList.map(c => {
            const bd = breakdownMap.get(c.id);
            return (
              <ContractorCard
                key={c.id}
                contractor={c}
                activeWorkers={bd?.active_workers ?? null}
                inactiveWorkers={bd?.inactive_workers ?? null}
                pendingExceptions={bd?.pending_exceptions ?? 0}
              />
            );
          })}
        </div>
      )}

      {/* Settlement section */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {/* Week selector + donut */}
        <div className="bg-card rounded-xl border border-border p-5 space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-sm text-foreground">Settlement Distribution</h2>
            <input
              type="week"
              value={weekRef}
              onChange={e => setWeekRef(e.target.value)}
              className="text-xs border border-border rounded-md px-2 py-1 bg-background text-foreground"
            />
          </div>
          {settlement.isPending ? (
            <Skeleton className="h-36 rounded-xl"/>
          ) : settlementData ? (
            <SettlementDonut lines={settlementData.lines}/>
          ) : (
            <div className="h-36 flex items-center justify-center text-xs text-muted-foreground">
              No settlement for {weekRef}
            </div>
          )}
        </div>

        {/* Settlement table */}
        <div className="lg:col-span-2 bg-card rounded-xl border border-border overflow-hidden">
          <div className="px-5 py-3 border-b border-border">
            <h2 className="font-semibold text-sm text-foreground">
              Settlement Breakdown — {weekRef}
            </h2>
          </div>
          {settlement.isPending ? (
            <div className="p-5 space-y-2">
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} className="h-9 rounded-lg"/>
              ))}
            </div>
          ) : !settlementData || settlementData.lines.length === 0 ? (
            <div className="p-8 text-center text-sm text-muted-foreground">
              No settlement data for {weekRef}.
            </div>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/40">
                  {['Contractor', 'Workers', 'Gross', 'Deductions', 'Net Settlement'].map(h => (
                    <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {settlementData.lines.map(line => (
                  <tr key={line.contractor_id} className="border-b border-border last:border-0 hover:bg-muted/20">
                    <td className="px-4 py-3 font-medium text-foreground">{line.contractor_name}</td>
                    <td className="px-4 py-3 text-muted-foreground">{line.worker_count}</td>
                    <td className="px-4 py-3 font-mono text-xs">{formatPKR(line.gross_earnings)}</td>
                    <td className="px-4 py-3 font-mono text-xs text-red-600">
                      -{formatPKR(line.deductions)}
                    </td>
                    <td className="px-4 py-3 font-mono font-semibold text-foreground">
                      {formatPKR(line.net_settlement)}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-border bg-muted/40">
                  <td colSpan={2} className="px-4 py-3 font-bold text-foreground text-sm">Total</td>
                  <td className="px-4 py-3 font-mono font-bold">{formatPKR(settlementData.total_gross)}</td>
                  <td/>
                  <td className="px-4 py-3 font-mono font-bold text-brand-dark">{formatPKR(settlementData.total_net)}</td>
                </tr>
              </tfoot>
            </table>
          )}
        </div>
      </div>

      {/* Contractor list table */}
      {contractorList.length > 0 && (
        <div className="bg-card rounded-xl border border-border overflow-hidden">
          <div className="px-5 py-3 border-b border-border">
            <h2 className="font-semibold text-sm text-foreground">All Contractors</h2>
          </div>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/40">
                {['Contractor', 'Contact', 'Cycle', 'Contract Start', 'Contract End', 'Status'].map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {contractorList.map(c => (
                <tr key={c.id} className="border-b border-border last:border-0 hover:bg-muted/20">
                  <td className="px-4 py-3 font-medium">{c.name}</td>
                  <td className="px-4 py-3 text-muted-foreground text-xs">{c.contact_person}</td>
                  <td className="px-4 py-3 capitalize text-muted-foreground text-xs">{c.payment_cycle}</td>
                  <td className="px-4 py-3 text-muted-foreground text-xs">{c.contract_start}</td>
                  <td className="px-4 py-3 text-muted-foreground text-xs">{c.contract_end ?? 'Ongoing'}</td>
                  <td className="px-4 py-3">
                    <Badge className={`text-xs border-0 capitalize ${STATUS_COLOR[c.status] ?? ''}`}>
                      {c.status}
                    </Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

// ── Sub-components ────────────────────────────────────────────────────────────

function SummaryCard({
  icon, bg, label, value,
}: {
  icon: React.ReactNode; bg: string; label: string; value: string;
}) {
  return (
    <div className="bg-card rounded-xl border border-border p-4 flex items-center gap-4">
      <div className={`p-2.5 rounded-lg ${bg}`}>{icon}</div>
      <div>
        <p className="text-xs text-muted-foreground uppercase tracking-wide font-medium">{label}</p>
        <p className="text-2xl font-bold text-foreground">{value}</p>
      </div>
    </div>
  );
}

function ContractorCard({
  contractor: c,
  activeWorkers,
  inactiveWorkers,
  pendingExceptions,
}: {
  contractor:        Contractor;
  activeWorkers:     number | null;
  inactiveWorkers:   number | null;
  pendingExceptions: number;
}) {
  const statusColor = STATUS_COLOR[c.status] ?? '';
  const active   = activeWorkers   ?? 0;
  const inactive = inactiveWorkers ?? 0;
  const loading  = activeWorkers === null;

  return (
    <div className="bg-card rounded-xl border border-border p-5 space-y-3">
      <div className="flex items-start justify-between gap-3">
        <div className="flex-1 min-w-0">
          <h3 className="font-semibold text-foreground truncate">{c.name}</h3>
          <p className="text-xs text-muted-foreground mt-0.5">{c.contact_person}</p>
        </div>
        <div className="flex flex-col items-end gap-1">
          <Badge className={`text-xs border-0 capitalize shrink-0 ${statusColor}`}>{c.status}</Badge>
          {pendingExceptions > 0 && (
            <Badge className="bg-red-100 text-red-700 border-0 text-xs">{pendingExceptions} exc.</Badge>
          )}
        </div>
      </div>

      {loading ? (
        <Skeleton className="h-[110px] rounded-lg"/>
      ) : (
        <WorkerDonut active={active} inactive={inactive}/>
      )}

      <div className="flex justify-between text-xs text-muted-foreground border-t border-border pt-3">
        <span>
          {loading ? (
            <Skeleton className="h-3 w-20 inline-block"/>
          ) : (
            <><span className="font-semibold text-foreground">{active}</span> active workers</>
          )}
        </span>
        <span className="capitalize">{c.payment_cycle}</span>
      </div>
    </div>
  );
}
