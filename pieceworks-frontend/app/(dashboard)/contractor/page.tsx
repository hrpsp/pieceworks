'use client';

import { useState }      from 'react';
import { useQuery }      from '@tanstack/react-query';
import { apiClient }     from '@/lib/api-client';
import {
  useContractorDashboard,
  useContractorSettlement,
  useContractorsList,
  useCreateContractor,
  useUpdateContractor,
  useDeleteContractor,
  type Contractor,
  type ContractorPayload,
  type ContractorWorkerBreakdown,
  type ContractorSettlementLine,
} from '@/hooks/useContractor';
import { formatPKR }     from '@/lib/formatters';
import { Skeleton }      from '@/components/ui/skeleton';
import { Badge }         from '@/components/ui/badge';
import { Button }        from '@/components/ui/button';
import { Input }         from '@/components/ui/input';
import { Label }         from '@/components/ui/label';
import {
  Dialog, DialogContent, DialogHeader,
  DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Building2, Users, TrendingUp, AlertCircle, Wallet,
  Plus, Pencil, Trash2, Loader2,
} from 'lucide-react';
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from 'recharts';

// ── Types ─────────────────────────────────────────────────────────────────────

interface ContractorFormState {
  name:                string;
  ntn_cnic:            string;
  contact_person:      string;
  phone:               string;
  whatsapp:            string;
  contract_start_date: string;
  contract_end_date:   string;
  payment_cycle:       'weekly' | 'biweekly' | 'monthly';
  bank_account:        string;
  bank_name:           string;
  tor_rate_pct:        string;
  status:              'active' | 'suspended' | 'expired';
  portal_access:       boolean;
}

const BLANK_FORM: ContractorFormState = {
  name: '', ntn_cnic: '', contact_person: '', phone: '', whatsapp: '',
  contract_start_date: '', contract_end_date: '',
  payment_cycle: 'weekly', bank_account: '', bank_name: '',
  tor_rate_pct: '0', status: 'active', portal_access: false,
};

function contractorToForm(c: Contractor): ContractorFormState {
  return {
    name:                c.name                 ?? '',
    ntn_cnic:            c.ntn_cnic             ?? '',
    contact_person:      c.contact_person       ?? '',
    phone:               c.phone                ?? '',
    whatsapp:            c.whatsapp             ?? '',
    contract_start_date: c.contract_start_date  ?? '',
    contract_end_date:   c.contract_end_date    ?? '',
    payment_cycle:       c.payment_cycle        ?? 'weekly',
    bank_account:        c.bank_account         ?? '',
    bank_name:           c.bank_name            ?? '',
    tor_rate_pct:        String(c.tor_rate_pct  ?? 0),
    status:              c.status               ?? 'active',
    portal_access:       c.portal_access        ?? false,
  };
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
        <Tooltip formatter={(v) => [v, '']} contentStyle={{ fontSize: 11, borderRadius: 6 }}/>
      </PieChart>
    </ResponsiveContainer>
  );
}

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
          data={data} cx="50%" cy="50%"
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
  active:    'bg-green-100 text-green-700',
  inactive:  'bg-amber-100 text-amber-700',
  suspended: 'bg-amber-100 text-amber-700',
  expired:   'bg-red-100 text-red-700',
};

// ── Contractor CRUD modal ────────────────────────────────────────────────────

function ContractorModal({
  editing,
  onClose,
}: {
  editing: Contractor | null;
  onClose: () => void;
}) {
  const [form, setForm] = useState<ContractorFormState>(
    editing ? contractorToForm(editing) : BLANK_FORM
  );
  const [error, setError] = useState('');

  const create = useCreateContractor();
  const update = useUpdateContractor(editing?.id ?? 0);
  const isPending = create.isPending || update.isPending;

  function set(key: keyof ContractorFormState, value: string | boolean) {
    setForm(f => ({ ...f, [key]: value }));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    if (!form.name.trim()) { setError('Contractor name is required.'); return; }

    const payload: ContractorPayload = {
      name:                form.name.trim(),
      ntn_cnic:            form.ntn_cnic     || undefined,
      contact_person:      form.contact_person || undefined,
      phone:               form.phone        || undefined,
      whatsapp:            form.whatsapp     || undefined,
      contract_start_date: form.contract_start_date || undefined,
      contract_end_date:   form.contract_end_date   || undefined,
      payment_cycle:       form.payment_cycle,
      bank_account:        form.bank_account || undefined,
      bank_name:           form.bank_name    || undefined,
      tor_rate_pct:        parseFloat(form.tor_rate_pct) || 0,
      status:              form.status,
      portal_access:       form.portal_access,
    };

    try {
      if (editing) {
        await update.mutateAsync(payload);
      } else {
        await create.mutateAsync(payload);
      }
      onClose();
    } catch (err: any) {
      setError(err?.message ?? 'Failed to save contractor.');
    }
  }

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{editing ? 'Edit Contractor' : 'Add Contractor'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4 py-1">
          {/* Name */}
          <div className="space-y-1.5">
            <Label className="text-xs">Contractor Name <span className="text-destructive">*</span></Label>
            <Input value={form.name} onChange={e => set('name', e.target.value)} placeholder="Khan Labour Services"/>
          </div>

          {/* NTN / CNIC */}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label className="text-xs">NTN / CNIC</Label>
              <Input value={form.ntn_cnic} onChange={e => set('ntn_cnic', e.target.value)} placeholder="0001234-5"/>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs">Contact Person</Label>
              <Input value={form.contact_person} onChange={e => set('contact_person', e.target.value)} placeholder="Imran Khan"/>
            </div>
          </div>

          {/* Phone / WhatsApp */}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label className="text-xs">Phone</Label>
              <Input value={form.phone} onChange={e => set('phone', e.target.value)} placeholder="0300-1234567"/>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs">WhatsApp</Label>
              <Input value={form.whatsapp} onChange={e => set('whatsapp', e.target.value)} placeholder="0300-1234567"/>
            </div>
          </div>

          {/* Contract dates */}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label className="text-xs">Contract Start</Label>
              <Input type="date" value={form.contract_start_date} onChange={e => set('contract_start_date', e.target.value)}/>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs">Contract End <span className="text-muted-foreground">(blank = ongoing)</span></Label>
              <Input type="date" value={form.contract_end_date} onChange={e => set('contract_end_date', e.target.value)}/>
            </div>
          </div>

          {/* Payment cycle + TOR */}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label className="text-xs">Payment Cycle</Label>
              <Select value={form.payment_cycle} onValueChange={v => set('payment_cycle', v as any)}>
                <SelectTrigger className="text-sm h-9">
                  <SelectValue/>
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="weekly">Weekly</SelectItem>
                  <SelectItem value="biweekly">Biweekly</SelectItem>
                  <SelectItem value="monthly">Monthly</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs">TOR Rate % <span className="text-muted-foreground">(overhead on wages)</span></Label>
              <Input
                type="number" min="0" max="100" step="0.5"
                value={form.tor_rate_pct}
                onChange={e => set('tor_rate_pct', e.target.value)}
                placeholder="15"
              />
            </div>
          </div>

          {/* Bank */}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label className="text-xs">Bank Name</Label>
              <Input value={form.bank_name} onChange={e => set('bank_name', e.target.value)} placeholder="HBL"/>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs">Bank Account</Label>
              <Input value={form.bank_account} onChange={e => set('bank_account', e.target.value)} placeholder="PK12HABB00010001234567"/>
            </div>
          </div>

          {/* Status */}
          <div className="space-y-1.5">
            <Label className="text-xs">Status</Label>
            <Select value={form.status} onValueChange={v => set('status', v as any)}>
              <SelectTrigger className="text-sm h-9">
                <SelectValue/>
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="suspended">Suspended</SelectItem>
                <SelectItem value="expired">Expired</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {error && <p className="text-xs text-destructive">{error}</p>}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button
              type="submit"
              disabled={isPending}
              className="bg-brand-dark hover:bg-brand-mid text-white"
            >
              {isPending && <Loader2 size={13} className="animate-spin mr-1.5"/>}
              {editing ? 'Save Changes' : 'Add Contractor'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

// ── Delete confirmation modal ─────────────────────────────────────────────────

function DeleteModal({
  contractor,
  onClose,
}: {
  contractor: Contractor;
  onClose: () => void;
}) {
  const del = useDeleteContractor();

  function confirm() {
    del.mutate(contractor.id, { onSuccess: onClose });
  }

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Deactivate Contractor</DialogTitle>
        </DialogHeader>
        <p className="text-sm text-muted-foreground py-2">
          This will mark <span className="font-semibold text-foreground">{contractor.name}</span> as{' '}
          <span className="text-amber-600 font-medium">expired</span>. Workers assigned to this contractor
          will remain in the system. You can re-activate them later.
        </p>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button
            onClick={confirm}
            disabled={del.isPending}
            className="bg-red-600 hover:bg-red-700 text-white"
          >
            {del.isPending && <Loader2 size={13} className="animate-spin mr-1.5"/>}
            Deactivate
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function ContractorPage() {
  const [weekRef, setWeekRef] = useState(currentWeekRef());
  const [modalOpen,   setModalOpen]   = useState(false);
  const [editing,     setEditing]     = useState<Contractor | null>(null);
  const [deleting,    setDeleting]    = useState<Contractor | null>(null);

  const contractorsList = useContractorsList();
  const dashboard       = useContractorDashboard();
  const settlement      = useContractorSettlement(weekRef);

  const contractorList  = (contractorsList.data?.data?.data as any[]) ?? [];
  const dash            = dashboard.data?.data;
  const settlementData  = settlement.data?.data;

  const breakdownMap = new Map<number, ContractorWorkerBreakdown>(
    (dash?.breakdown ?? []).map(b => [b.contractor_id, b])
  );

  function openAdd() {
    setEditing(null);
    setModalOpen(true);
  }

  function openEdit(c: Contractor) {
    setEditing(c);
    setModalOpen(true);
  }

  return (
    <div className="p-6 space-y-6 max-w-7xl mx-auto">

      {/* Modals */}
      {modalOpen && (
        <ContractorModal editing={editing} onClose={() => setModalOpen(false)}/>
      )}
      {deleting && (
        <DeleteModal contractor={deleting} onClose={() => setDeleting(null)}/>
      )}

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Contractors</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Manage contractors, TOR rates, and settlement overview
          </p>
        </div>
        <Button
          onClick={openAdd}
          className="bg-brand-dark hover:bg-brand-mid text-white gap-2"
        >
          <Plus size={15}/> Add Contractor
        </Button>
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
      {contractorsList.isPending ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-56 rounded-xl"/>
          ))}
        </div>
      ) : contractorsList.isError ? (
        <div className="bg-card rounded-xl border border-border p-12 text-center space-y-3">
          <AlertCircle size={32} className="mx-auto text-muted-foreground/40"/>
          <p className="text-muted-foreground text-sm">Could not load contractors.</p>
        </div>
      ) : contractorList.length === 0 ? (
        <div className="bg-card rounded-xl border border-border p-12 text-center space-y-3">
          <Building2 size={32} className="mx-auto text-muted-foreground/40"/>
          <p className="text-muted-foreground text-sm">No contractors yet.</p>
          <Button onClick={openAdd} className="bg-brand-dark hover:bg-brand-mid text-white gap-2">
            <Plus size={14}/> Add First Contractor
          </Button>
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
                onEdit={() => openEdit(c)}
                onDelete={() => setDeleting(c)}
              />
            );
          })}
        </div>
      )}

      {/* Settlement section */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
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
              No settlement data for {weekRef}. Lock and release a payroll run first.
            </div>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/40">
                  {['Contractor', 'Workers', 'Gross', 'Deductions', 'Net Settlement'].map(h => (
                    <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {settlementData.lines.map(line => (
                  <tr key={line.contractor_id} className="border-b border-border last:border-0 hover:bg-muted/20">
                    <td className="px-4 py-3 font-medium text-foreground">{line.contractor_name}</td>
                    <td className="px-4 py-3 text-muted-foreground">{line.worker_count}</td>
                    <td className="px-4 py-3 font-mono text-xs">{formatPKR(line.gross_earnings)}</td>
                    <td className="px-4 py-3 font-mono text-xs text-red-600">-{formatPKR(line.deductions)}</td>
                    <td className="px-4 py-3 font-mono font-semibold text-foreground">{formatPKR(line.net_settlement)}</td>
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

      {/* Full list table with actions */}
      {contractorList.length > 0 && (
        <div className="bg-card rounded-xl border border-border overflow-hidden">
          <div className="px-5 py-3 border-b border-border flex items-center justify-between">
            <h2 className="font-semibold text-sm text-foreground">All Contractors</h2>
            <span className="text-xs text-muted-foreground">{contractorList.length} total</span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/40">
                  {['Contractor', 'Contact', 'Cycle', 'TOR %', 'Contract Start', 'Contract End', 'Status', ''].map(h => (
                    <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {contractorList.map(c => (
                  <tr key={c.id} className="border-b border-border last:border-0 hover:bg-muted/20">
                    <td className="px-4 py-3 font-medium">{c.name}</td>
                    <td className="px-4 py-3 text-muted-foreground text-xs">{c.contact_person ?? '—'}</td>
                    <td className="px-4 py-3 capitalize text-muted-foreground text-xs">{c.payment_cycle}</td>
                    <td className="px-4 py-3 text-xs font-mono">
                      {c.tor_rate_pct != null && c.tor_rate_pct > 0
                        ? <span className="bg-purple-50 text-purple-700 px-1.5 py-0.5 rounded">{c.tor_rate_pct}%</span>
                        : <span className="text-muted-foreground">—</span>
                      }
                    </td>
                    <td className="px-4 py-3 text-muted-foreground text-xs">{c.contract_start_date ?? '—'}</td>
                    <td className="px-4 py-3 text-muted-foreground text-xs">{c.contract_end_date ?? 'Ongoing'}</td>
                    <td className="px-4 py-3">
                      <Badge className={`text-xs border-0 capitalize ${STATUS_COLOR[c.status] ?? ''}`}>
                        {c.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1">
                        <button
                          onClick={() => openEdit(c)}
                          className="p-1.5 rounded hover:bg-muted text-muted-foreground hover:text-foreground transition-colors"
                          title="Edit contractor"
                        >
                          <Pencil size={13}/>
                        </button>
                        <button
                          onClick={() => setDeleting(c)}
                          className="p-1.5 rounded hover:bg-red-50 text-muted-foreground hover:text-red-600 transition-colors"
                          title="Deactivate contractor"
                        >
                          <Trash2 size={13}/>
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
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
  onEdit,
  onDelete,
}: {
  contractor:        Contractor;
  activeWorkers:     number | null;
  inactiveWorkers:   number | null;
  pendingExceptions: number;
  onEdit:            () => void;
  onDelete:          () => void;
}) {
  const statusColor = STATUS_COLOR[c.status] ?? '';
  const active      = activeWorkers   ?? 0;
  const inactive    = inactiveWorkers ?? 0;
  const loading     = activeWorkers === null;

  return (
    <div className="bg-card rounded-xl border border-border p-5 space-y-3">
      <div className="flex items-start justify-between gap-3">
        <div className="flex-1 min-w-0">
          <h3 className="font-semibold text-foreground truncate">{c.name}</h3>
          <p className="text-xs text-muted-foreground mt-0.5">{c.contact_person ?? '—'}</p>
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

      <div className="border-t border-border pt-3 space-y-2">
        <div className="flex justify-between text-xs text-muted-foreground">
          <span>
            {loading ? (
              <Skeleton className="h-3 w-20 inline-block"/>
            ) : (
              <><span className="font-semibold text-foreground">{active}</span> active workers</>
            )}
          </span>
          <span className="capitalize">{c.payment_cycle}</span>
        </div>
        {c.tor_rate_pct != null && c.tor_rate_pct > 0 && (
          <div className="flex justify-between text-xs">
            <span className="text-muted-foreground">TOR Rate</span>
            <span className="font-semibold text-purple-700">{c.tor_rate_pct}%</span>
          </div>
        )}
        <div className="flex items-center justify-end gap-1 pt-1">
          <button
            onClick={onEdit}
            className="flex items-center gap-1 text-xs text-brand-dark border border-brand-dark/30 hover:bg-brand-dark hover:text-white rounded px-2 py-1 transition-colors"
          >
            <Pencil size={11}/> Edit
          </button>
          <button
            onClick={onDelete}
            className="flex items-center gap-1 text-xs text-red-600 border border-red-200 hover:bg-red-600 hover:text-white rounded px-2 py-1 transition-colors"
          >
            <Trash2 size={11}/> Deactivate
          </button>
        </div>
      </div>
    </div>
  );
}
