'use client';

/**
 * Settings / Admin page
 *
 * Tabs:
 *   1. Users            — list system users; assign / revoke roles
 *   2. Roles & Perms    — role catalogue with permission matrix
 *   3. Factory Locations — factory list (endpoint TBD; shows empty state until added)
 *   4. System Config    — read-only display of key config values
 *
 * Backend endpoints used:
 *   GET  /roles                          — list roles + permissions
 *   GET  /users                          — list users (not yet added → graceful empty)
 *   GET  /users/{id}/permissions         — resolved perms for a user
 *   POST /users/{id}/roles               — assign role
 *   DELETE /users/{id}/roles/{slug}      — revoke role
 *   GET  /admin/factory-locations        — factory list (not yet added → graceful empty)
 */

import { useState }          from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient }          from '@/lib/api-client';
import { useAuth }            from '@/hooks/useAuth';
import { Badge }              from '@/components/ui/badge';
import { Button }             from '@/components/ui/button';
import { Skeleton }           from '@/components/ui/skeleton';
import { Input }              from '@/components/ui/input';
import {
  Tabs, TabsContent, TabsList, TabsTrigger,
} from '@/components/ui/tabs';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Label }             from '@/components/ui/label';
import {
  Users, Shield, MapPin, Settings2,
  CheckCircle2, XCircle, Plus, Trash2,
  Building2, ChevronDown, ChevronRight,
  AlertTriangle, Loader2, Percent, X, UserPlus,
} from 'lucide-react';

// ── Types ─────────────────────────────────────────────────────────────────────

interface RolePermission {
  slug:   string;
  name:   string;
  module: string;
}

interface Role {
  id:          number;
  name:        string;
  slug:        string;
  description: string | null;
  permissions: RolePermission[];
}

interface SystemUser {
  id:          number;
  name:        string;
  email:       string;
  role:        string;
  roles?:      string[];
  created_at?: string;
}

interface FactoryLocation {
  id:        number;
  name:      string;
  city:      string;
  province:  string;
  address:   string | null;
  is_active: boolean;
}

// ── Query keys ────────────────────────────────────────────────────────────────

const settingsKeys = {
  roles:            ['settings', 'roles']        as const,
  users:            ['settings', 'users']        as const,
  factoryLocations: ['settings', 'factory-locs'] as const,
};

// ── Hooks ─────────────────────────────────────────────────────────────────────

function useRoles() {
  return useQuery({
    queryKey: settingsKeys.roles,
    queryFn:  () => apiClient.get<{ data: Role[]; message: string }>('/roles'),
  });
}

function useSystemUsers() {
  return useQuery({
    queryKey: settingsKeys.users,
    queryFn:  () => apiClient.get<{ data: SystemUser[]; message: string }>('/admin/users'),
    retry:    false, // endpoint may not exist yet
  });
}

function useFactoryLocations() {
  return useQuery({
    queryKey: settingsKeys.factoryLocations,
    queryFn:  () => apiClient.get<{ data: FactoryLocation[]; message: string }>('/admin/factory-locations'),
    retry:    false,
  });
}

function useAssignRole(userId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (roleSlug: string) =>
      apiClient.post<{ data: unknown; message: string }>(`/users/${userId}/roles`, { role_slug: roleSlug }),
    onSuccess: () => qc.invalidateQueries({ queryKey: settingsKeys.users }),
  });
}

function useRevokeRole(userId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (roleSlug: string) =>
      apiClient.delete<{ data: unknown; message: string }>(`/users/${userId}/roles/${roleSlug}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: settingsKeys.users }),
  });
}

function useInviteUser() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { name: string; email: string; role_slug: string; password: string }) =>
      apiClient.post<{ data: unknown; message: string }>('/admin/users', body),
    onSuccess: () => qc.invalidateQueries({ queryKey: settingsKeys.users }),
  });
}

function useAddFactoryLocation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { name: string; city: string; province: string; address?: string; is_active: boolean }) =>
      apiClient.post<{ data: unknown; message: string }>('/admin/factory-locations', body),
    onSuccess: () => qc.invalidateQueries({ queryKey: settingsKeys.factoryLocations }),
  });
}

interface ComplianceConfig {
  eobi_employer_rate_pct:   number;
  pessi_employer_rate_pct:  number;
  min_wage_punjab:          number;
  min_wage_sindh:           number;
  min_wage_kpk:             number;
  min_wage_balochistan:     number;
  wht_threshold:            number;
  wht_rate_non_filer_pct:   number;
}

function useComplianceConfig() {
  return useQuery({
    queryKey: ['settings', 'compliance-config'],
    queryFn:  () => apiClient.get<{ data: ComplianceConfig }>('/admin/compliance-config'),
    retry: false,
  });
}

function usePatchComplianceConfig() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: Partial<ComplianceConfig>) =>
      apiClient.patch<{ data: ComplianceConfig }>('/admin/compliance-config', body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings', 'compliance-config'] }),
  });
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function moduleColor(module: string): string {
  const palette: Record<string, string> = {
    workers:    'bg-blue-50   text-blue-700   border-blue-200',
    production: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    payroll:    'bg-purple-50 text-purple-700 border-purple-200',
    reports:    'bg-orange-50 text-orange-700 border-orange-200',
    rate_cards: 'bg-pink-50   text-pink-700   border-pink-200',
    advances:   'bg-cyan-50   text-cyan-700   border-cyan-200',
    rejection:  'bg-red-50    text-red-700    border-red-200',
    ghost_worker:'bg-amber-50 text-amber-700  border-amber-200',
  };
  return palette[module] ?? 'bg-slate-100 text-slate-600 border-slate-200';
}

// ── Section header ────────────────────────────────────────────────────────────

function SectionHeader({ icon: Icon, title, sub }: { icon: React.ElementType; title: string; sub?: string }) {
  return (
    <div className="flex items-start gap-3 mb-5">
      <div className="p-2 rounded-lg bg-brand-dark/5 shrink-0">
        <Icon size={16} className="text-brand-dark" />
      </div>
      <div>
        <h2 className="text-sm font-semibold text-foreground">{title}</h2>
        {sub && <p className="text-xs text-muted-foreground mt-0.5">{sub}</p>}
      </div>
    </div>
  );
}

// ── Endpoint-unavailable placeholder ─────────────────────────────────────────

function EndpointPlaceholder({ label }: { label: string }) {
  return (
    <div className="rounded-xl border border-dashed border-amber-200 bg-amber-50/50 p-8 text-center">
      <AlertTriangle size={22} className="mx-auto text-amber-400 mb-2" />
      <p className="text-sm font-medium text-amber-700">{label}</p>
      <p className="text-xs text-amber-600 mt-1">
        The backend endpoint for this section has not been implemented yet.<br />
        Add the route and controller method, then this panel will populate automatically.
      </p>
    </div>
  );
}

// ── Invite User Modal ─────────────────────────────────────────────────────────

function InviteUserModal({ roles, onClose }: { roles: Role[]; onClose: () => void }) {
  const [name,     setName]     = useState('');
  const [email,    setEmail]    = useState('');
  const [roleSlug, setRoleSlug] = useState('');
  const [password, setPassword] = useState('');
  const [err,      setErr]      = useState('');

  const invite = useInviteUser();

  function submit() {
    if (!name.trim() || !email.trim() || !roleSlug || !password) {
      setErr('All fields are required.'); return;
    }
    invite.mutate(
      { name: name.trim(), email: email.trim(), role_slug: roleSlug, password },
      { onSuccess: onClose, onError: () => setErr('Failed to create user. Email may already exist.') }
    );
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6 space-y-5">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2.5">
            <div className="w-9 h-9 rounded-xl bg-[#322E53] flex items-center justify-center">
              <UserPlus size={15} className="text-white"/>
            </div>
            <h2 className="font-bold text-foreground text-sm">Invite User</h2>
          </div>
          <button onClick={onClose} className="text-muted-foreground hover:text-foreground"><X size={15}/></button>
        </div>

        {err && (
          <div className="flex items-center gap-2 px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-700">
            <AlertTriangle size={12}/> {err}
          </div>
        )}

        <div className="space-y-3">
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">Full Name *</Label>
            <Input value={name} onChange={e => setName(e.target.value)} placeholder="Ahmed Raza" className="h-9 text-sm"/>
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">Email Address *</Label>
            <Input type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="ahmed@factory.pk" className="h-9 text-sm"/>
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">Role *</Label>
            <select
              value={roleSlug}
              onChange={e => setRoleSlug(e.target.value)}
              className="w-full h-9 text-sm border border-input rounded-md px-3 bg-background text-foreground"
            >
              <option value="">Select role…</option>
              {roles.map(r => <option key={r.slug} value={r.slug}>{r.name}</option>)}
            </select>
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">Temporary Password *</Label>
            <Input type="password" value={password} onChange={e => setPassword(e.target.value)} placeholder="Min 8 characters" className="h-9 text-sm"/>
          </div>
        </div>

        <div className="flex justify-end gap-3 pt-2 border-t border-border">
          <Button variant="outline" size="sm" onClick={onClose} disabled={invite.isPending}>Cancel</Button>
          <Button size="sm" onClick={submit} disabled={invite.isPending} className="bg-[#322E53] hover:bg-[#49426E] text-white gap-1.5">
            {invite.isPending ? <><Loader2 size={13} className="animate-spin"/> Creating…</> : <><CheckCircle2 size={13}/> Create User</>}
          </Button>
        </div>
      </div>
    </div>
  );
}

// ── Add Factory Location Modal ────────────────────────────────────────────────

function AddLocationModal({ onClose }: { onClose: () => void }) {
  const [name,     setName]     = useState('');
  const [city,     setCity]     = useState('');
  const [province, setProvince] = useState('Punjab');
  const [address,  setAddress]  = useState('');
  const [active,   setActive]   = useState(true);
  const [err,      setErr]      = useState('');

  const addLoc = useAddFactoryLocation();

  function submit() {
    if (!name.trim() || !city.trim()) { setErr('Name and city are required.'); return; }
    addLoc.mutate(
      { name: name.trim(), city: city.trim(), province, address: address.trim() || undefined, is_active: active },
      { onSuccess: onClose, onError: () => setErr('Failed to add location.') }
    );
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6 space-y-5">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2.5">
            <div className="w-9 h-9 rounded-xl bg-[#322E53] flex items-center justify-center">
              <MapPin size={15} className="text-white"/>
            </div>
            <h2 className="font-bold text-foreground text-sm">Add Factory Location</h2>
          </div>
          <button onClick={onClose} className="text-muted-foreground hover:text-foreground"><X size={15}/></button>
        </div>

        {err && (
          <div className="flex items-center gap-2 px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-700">
            <AlertTriangle size={12}/> {err}
          </div>
        )}

        <div className="space-y-3">
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">Location Name *</Label>
            <Input value={name} onChange={e => setName(e.target.value)} placeholder="Factory A – Lahore" className="h-9 text-sm"/>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label className="text-xs text-muted-foreground">City *</Label>
              <Input value={city} onChange={e => setCity(e.target.value)} placeholder="Lahore" className="h-9 text-sm"/>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs text-muted-foreground">Province</Label>
              <select
                value={province}
                onChange={e => setProvince(e.target.value)}
                className="w-full h-9 text-sm border border-input rounded-md px-3 bg-background text-foreground"
              >
                {['Punjab','Sindh','KPK','Balochistan','AJK','Gilgit-Baltistan'].map(p => (
                  <option key={p} value={p}>{p}</option>
                ))}
              </select>
            </div>
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">Address</Label>
            <Input value={address} onChange={e => setAddress(e.target.value)} placeholder="Optional street address…" className="h-9 text-sm"/>
          </div>
          <div className="flex items-center gap-2">
            <input type="checkbox" id="loc-active" checked={active} onChange={e => setActive(e.target.checked)} className="rounded"/>
            <Label htmlFor="loc-active" className="text-sm text-foreground cursor-pointer">Active</Label>
          </div>
        </div>

        <div className="flex justify-end gap-3 pt-2 border-t border-border">
          <Button variant="outline" size="sm" onClick={onClose} disabled={addLoc.isPending}>Cancel</Button>
          <Button size="sm" onClick={submit} disabled={addLoc.isPending} className="bg-[#322E53] hover:bg-[#49426E] text-white gap-1.5">
            {addLoc.isPending ? <><Loader2 size={13} className="animate-spin"/> Saving…</> : <><CheckCircle2 size={13}/> Add Location</>}
          </Button>
        </div>
      </div>
    </div>
  );
}

// ── 1. Users tab ─────────────────────────────────────────────────────────────

function UsersTab() {
  const users    = useSystemUsers();
  const rolesQ   = useRoles();
  const [roleDialog,   setRoleDialog]   = useState<SystemUser | null>(null);
  const [selectedRole, setSelectedRole] = useState('');
  const [inviteOpen,   setInviteOpen]   = useState(false);

  const assignRole  = useAssignRole(roleDialog?.id ?? 0);
  const revokeRole  = useRevokeRole(roleDialog?.id ?? 0);

  const roles = rolesQ.data?.data ?? [];

  async function handleAssign() {
    if (!selectedRole || !roleDialog) return;
    await assignRole.mutateAsync(selectedRole);
    setSelectedRole('');
    setRoleDialog(null);
  }

  // Endpoint not yet added — show placeholder
  if (users.isError) {
    return <EndpointPlaceholder label="GET /admin/users — user management endpoint not yet available" />;
  }

  return (
    <div>
      <div className="flex items-start justify-between">
        <SectionHeader
          icon={Users}
          title="System Users"
          sub="Manage user accounts and role assignments"
        />
        <Button
          size="sm"
          className="bg-[#322E53] hover:bg-[#49426E] text-white gap-1.5 h-8 shrink-0"
          onClick={() => setInviteOpen(true)}
        >
          <UserPlus size={13}/> Invite User
        </Button>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-muted/40 text-xs text-muted-foreground uppercase tracking-wide">
            <tr>
              <th className="px-4 py-3 text-left font-medium">Name</th>
              <th className="px-4 py-3 text-left font-medium">Email</th>
              <th className="px-4 py-3 text-left font-medium">Role(s)</th>
              <th className="px-4 py-3 text-left font-medium">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {users.isPending ? (
              [...Array(4)].map((_, i) => (
                <tr key={i}>
                  {[...Array(4)].map((__, j) => (
                    <td key={j} className="px-4 py-3">
                      <Skeleton className="h-4 w-full rounded" />
                    </td>
                  ))}
                </tr>
              ))
            ) : (users.data?.data ?? []).length === 0 ? (
              <tr>
                <td colSpan={4} className="px-4 py-10 text-center text-sm text-muted-foreground">
                  No users found.
                </td>
              </tr>
            ) : (
              (users.data?.data ?? []).map(u => (
                <tr key={u.id} className="hover:bg-muted/30 transition-colors">
                  <td className="px-4 py-3 font-medium">{u.name}</td>
                  <td className="px-4 py-3 text-muted-foreground">{u.email}</td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      {(u.roles ?? [u.role]).filter(Boolean).map(r => (
                        <Badge key={r} variant="secondary" className="text-xs font-normal">
                          {r}
                        </Badge>
                      ))}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <Button
                      size="sm"
                      variant="outline"
                      className="h-7 text-xs"
                      onClick={() => { setRoleDialog(u); setSelectedRole(''); }}
                    >
                      <Shield size={11} className="mr-1" /> Manage Roles
                    </Button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Invite User modal */}
      {inviteOpen && (
        <InviteUserModal roles={roles} onClose={() => setInviteOpen(false)}/>
      )}

      {/* Role assignment dialog */}
      <Dialog open={!!roleDialog} onOpenChange={v => { if (!v) setRoleDialog(null); }}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle className="text-base">Manage Roles — {roleDialog?.name}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 pt-1">
            {/* Current roles */}
            <div>
              <p className="text-xs font-medium text-muted-foreground mb-2">Current Roles</p>
              <div className="flex flex-wrap gap-1.5">
                {(roleDialog?.roles ?? (roleDialog?.role ? [roleDialog.role] : [])).map(slug => (
                  <span
                    key={slug}
                    className="inline-flex items-center gap-1 px-2 py-1 rounded border text-xs bg-brand-dark/5 text-foreground border-border"
                  >
                    {slug}
                    <button
                      className="text-muted-foreground hover:text-red-600 transition-colors"
                      onClick={() => revokeRole.mutate(slug)}
                      disabled={revokeRole.isPending}
                      title="Remove role"
                    >
                      <XCircle size={11} />
                    </button>
                  </span>
                ))}
              </div>
            </div>

            {/* Assign new role */}
            <div>
              <p className="text-xs font-medium text-muted-foreground mb-1.5">Assign Role</p>
              <div className="flex gap-2">
                <Select value={selectedRole} onValueChange={setSelectedRole}>
                  <SelectTrigger className="h-8 text-sm flex-1">
                    <SelectValue placeholder="Select role…" />
                  </SelectTrigger>
                  <SelectContent>
                    {roles.map(r => (
                      <SelectItem key={r.slug} value={r.slug}>{r.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Button
                  size="sm"
                  className="h-8 bg-brand-dark hover:bg-brand-mid text-white"
                  onClick={handleAssign}
                  disabled={!selectedRole || assignRole.isPending}
                >
                  {assignRole.isPending ? <Loader2 size={13} className="animate-spin" /> : <Plus size={13} />}
                </Button>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="ghost" size="sm" onClick={() => setRoleDialog(null)}>Close</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

// ── 2. Roles & Permissions tab ────────────────────────────────────────────────

function RolesTab() {
  const rolesQ = useRoles();
  const [expanded, setExpanded] = useState<number | null>(null);

  const roles = rolesQ.data?.data ?? [];

  return (
    <div>
      <SectionHeader
        icon={Shield}
        title="Roles & Permissions"
        sub="Role catalogue with their assigned permission slugs"
      />

      {rolesQ.isPending ? (
        <div className="space-y-2">
          {[1, 2, 3].map(i => <Skeleton key={i} className="h-14 rounded-xl" />)}
        </div>
      ) : (
        <div className="space-y-2">
          {roles.map(role => {
            const isOpen = expanded === role.id;
            const byModule = role.permissions.reduce<Record<string, RolePermission[]>>((acc, p) => {
              (acc[p.module] ??= []).push(p);
              return acc;
            }, {});

            return (
              <div key={role.id} className="rounded-xl border border-border overflow-hidden">
                <button
                  className="w-full flex items-center justify-between px-4 py-3.5 hover:bg-muted/30 transition-colors"
                  onClick={() => setExpanded(isOpen ? null : role.id)}
                >
                  <div className="flex items-center gap-3">
                    <Shield size={15} className="text-brand-dark/60 shrink-0" />
                    <div className="text-left">
                      <span className="text-sm font-semibold text-foreground">{role.name}</span>
                      {role.description && (
                        <span className="ml-2 text-xs text-muted-foreground">{role.description}</span>
                      )}
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge variant="secondary" className="text-xs">
                      {role.permissions.length} permission{role.permissions.length !== 1 ? 's' : ''}
                    </Badge>
                    {isOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                  </div>
                </button>

                {isOpen && (
                  <div className="border-t border-border px-4 py-4 bg-muted/20">
                    {Object.keys(byModule).length === 0 ? (
                      <p className="text-xs text-muted-foreground italic">No permissions assigned.</p>
                    ) : (
                      <div className="space-y-3">
                        {Object.entries(byModule).map(([module, perms]) => (
                          <div key={module}>
                            <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-1.5">
                              {module.replace(/_/g, ' ')}
                            </p>
                            <div className="flex flex-wrap gap-1.5">
                              {perms.map(p => (
                                <span
                                  key={p.slug}
                                  className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border ${moduleColor(module)}`}
                                  title={p.name}
                                >
                                  {p.slug}
                                </span>
                              ))}
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

// ── 3. Factory Locations tab ──────────────────────────────────────────────────

function FactoryLocationsTab() {
  const locationsQ = useFactoryLocations();
  const [addLocOpen, setAddLocOpen] = useState(false);

  if (locationsQ.isError) {
    return <EndpointPlaceholder label="GET /admin/factory-locations — factory location endpoint not yet available" />;
  }

  const locations = locationsQ.data?.data ?? [];

  return (
    <div>
      <div className="flex items-start justify-between">
        <SectionHeader
          icon={MapPin}
          title="Factory Locations"
          sub="Bata factory sites registered in this PieceWorks instance"
        />
        <Button
          size="sm"
          className="bg-[#322E53] hover:bg-[#49426E] text-white gap-1.5 h-8 shrink-0"
          onClick={() => setAddLocOpen(true)}
        >
          <Plus size={13}/> Add Location
        </Button>
      </div>

      {addLocOpen && <AddLocationModal onClose={() => setAddLocOpen(false)}/>}

      {locationsQ.isPending ? (
        <div className="space-y-2">
          {[1, 2, 3].map(i => <Skeleton key={i} className="h-16 rounded-xl" />)}
        </div>
      ) : locations.length === 0 ? (
        <div className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
          <Building2 size={22} className="mx-auto text-muted-foreground/40 mb-2" />
          No factory locations configured.
        </div>
      ) : (
        <div className="rounded-xl border border-border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-muted/40 text-xs text-muted-foreground uppercase tracking-wide">
              <tr>
                <th className="px-4 py-3 text-left font-medium">Name</th>
                <th className="px-4 py-3 text-left font-medium">City</th>
                <th className="px-4 py-3 text-left font-medium">Province</th>
                <th className="px-4 py-3 text-left font-medium">Address</th>
                <th className="px-4 py-3 text-left font-medium">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {locations.map(loc => (
                <tr key={loc.id} className="hover:bg-muted/30 transition-colors">
                  <td className="px-4 py-3 font-medium">{loc.name}</td>
                  <td className="px-4 py-3 text-muted-foreground">{loc.city}</td>
                  <td className="px-4 py-3 text-muted-foreground">{loc.province}</td>
                  <td className="px-4 py-3 text-muted-foreground text-xs">{loc.address ?? '—'}</td>
                  <td className="px-4 py-3">
                    {loc.is_active ? (
                      <span className="inline-flex items-center gap-1 text-xs text-green-600">
                        <CheckCircle2 size={12} /> Active
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                        <XCircle size={12} /> Inactive
                      </span>
                    )}
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

// ── 4. Compliance Rates tab ───────────────────────────────────────────────────

function ComplianceRatesTab() {
  const configQ = useComplianceConfig();
  const patch   = usePatchComplianceConfig();

  const remote = (configQ.data as any)?.data as ComplianceConfig | undefined;

  const [form, setForm] = useState<Partial<ComplianceConfig>>({});
  const [saved, setSaved] = useState(false);

  // When remote data loads, seed the form (once)
  const [seeded, setSeeded] = useState(false);
  if (remote && !seeded) {
    setForm({ ...remote });
    setSeeded(true);
  }

  function field(key: keyof ComplianceConfig, label: string, hint?: string) {
    return (
      <div className="flex items-center justify-between py-3 border-b border-border last:border-0">
        <div>
          <p className="text-sm font-medium text-foreground">{label}</p>
          {hint && <p className="text-xs text-muted-foreground mt-0.5">{hint}</p>}
        </div>
        <div className="flex items-center gap-1.5">
          <Input
            type="number"
            step="0.01"
            value={form[key] ?? ''}
            onChange={e => setForm(f => ({ ...f, [key]: parseFloat(e.target.value) || 0 }))}
            className="h-8 w-28 text-sm text-right"
            disabled={patch.isPending}
          />
        </div>
      </div>
    );
  }

  function save() {
    patch.mutate(form, {
      onSuccess: () => { setSaved(true); setTimeout(() => setSaved(false), 2500); },
    });
  }

  function reset() {
    if (remote) { setForm({ ...remote }); }
  }

  if (configQ.isError) {
    return <EndpointPlaceholder label="GET /admin/compliance-config — compliance config endpoint not yet available" />;
  }

  return (
    <div>
      <div className="flex items-start justify-between">
        <SectionHeader
          icon={Percent}
          title="Compliance Rates"
          sub="EOBI, PESSI, minimum wages, and WHT thresholds — editable per factory"
        />
        <div className="flex gap-2 shrink-0">
          <Button variant="outline" size="sm" className="h-8 text-xs" onClick={reset} disabled={patch.isPending}>
            Reset
          </Button>
          <Button
            size="sm"
            className="h-8 bg-[#322E53] hover:bg-[#49426E] text-white gap-1.5 text-xs"
            onClick={save}
            disabled={patch.isPending}
          >
            {patch.isPending ? <><Loader2 size={12} className="animate-spin"/> Saving…</> : saved ? <><CheckCircle2 size={12}/> Saved!</> : 'Save Changes'}
          </Button>
        </div>
      </div>

      {configQ.isPending ? (
        <div className="space-y-2">
          {[1,2,3,4,5,6].map(i => <Skeleton key={i} className="h-12 rounded-xl"/>)}
        </div>
      ) : (
        <div className="space-y-5">
          {/* Social Contributions */}
          <div className="rounded-xl border border-border overflow-hidden">
            <div className="px-4 py-2.5 bg-muted/40 border-b border-border">
              <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Social Contributions (%)</p>
            </div>
            <div className="px-4">
              {field('eobi_employer_rate_pct',  'EOBI Employer Rate',  'Percentage of minimum wage — employer contribution')}
              {field('pessi_employer_rate_pct', 'PESSI Employer Rate', 'Percentage of gross salary — employer contribution')}
            </div>
          </div>

          {/* Minimum Wages */}
          <div className="rounded-xl border border-border overflow-hidden">
            <div className="px-4 py-2.5 bg-muted/40 border-b border-border">
              <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Minimum Monthly Wages (PKR)</p>
            </div>
            <div className="px-4">
              {field('min_wage_punjab',     'Punjab',     'Current provincial minimum wage')}
              {field('min_wage_sindh',      'Sindh',      'Current provincial minimum wage')}
              {field('min_wage_kpk',        'KPK',        'Current provincial minimum wage')}
              {field('min_wage_balochistan','Balochistan', 'Current provincial minimum wage')}
            </div>
          </div>

          {/* Withholding Tax */}
          <div className="rounded-xl border border-border overflow-hidden">
            <div className="px-4 py-2.5 bg-muted/40 border-b border-border">
              <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Withholding Tax (WHT)</p>
            </div>
            <div className="px-4">
              {field('wht_threshold',          'WHT Salary Threshold (PKR)', 'Monthly gross above which WHT applies')}
              {field('wht_rate_non_filer_pct', 'WHT Rate — Non-filer (%)',   'Applied to salary exceeding threshold for non-filers')}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ── 5. System Config tab ──────────────────────────────────────────────────────

const CONFIG_ITEMS = [
  {
    section: 'Payroll',
    items: [
      { key: 'Week cycle',           value: 'Monday → Sunday (ISO week)' },
      { key: 'Payroll run trigger',  value: 'Sunday 22:00 (auto) or manual' },
      { key: 'Min wage — Punjab',    value: 'PKR 37,000 / month' },
      { key: 'Min wage — Sindh',     value: 'PKR 35,000 / month' },
      { key: 'EOBI employer rate',   value: '5% of min wage' },
      { key: 'PESSI employer rate',  value: '6% of gross' },
      { key: 'WHT threshold',        value: 'Non-filers: 2.5% on salary > 1 lakh' },
    ],
  },
  {
    section: 'Bata Integration',
    items: [
      { key: 'Sync frequency',       value: 'Every 30 minutes (bata:sync)' },
      { key: 'Statements generate',  value: 'Sunday 22:15 (GenerateAllStatementsJob)' },
      { key: 'WhatsApp send',        value: 'Sunday 22:45 (SendAllStatementsJob)' },
      { key: 'Contractor expiry',    value: 'Daily 09:00 (CheckContractorExpiryJob)' },
      { key: 'Tenure milestones',    value: 'Daily 09:00 (CheckTenureMilestonesJob)' },
    ],
  },
  {
    section: 'Authentication',
    items: [
      { key: 'Auth driver',          value: 'Laravel Sanctum (personal access tokens)' },
      { key: 'Token storage',        value: 'localStorage pw_token' },
      { key: 'Contractor portal',    value: 'Separate middleware: contractor.portal' },
    ],
  },
  {
    section: 'Queue & Jobs',
    items: [
      { key: 'Queue driver',         value: 'database' },
      { key: 'Scheduler entry',      value: 'bootstrap/app.php withSchedule()' },
      { key: 'Failed jobs',          value: 'failed_jobs table' },
    ],
  },
];

function SystemConfigTab() {
  return (
    <div>
      <SectionHeader
        icon={Settings2}
        title="System Configuration"
        sub="Read-only reference — configured in config/pieceworks.php and bootstrap/app.php"
      />

      <div className="space-y-5">
        {CONFIG_ITEMS.map(section => (
          <div key={section.section} className="rounded-xl border border-border overflow-hidden">
            <div className="px-4 py-2.5 bg-muted/40 border-b border-border">
              <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                {section.section}
              </p>
            </div>
            <div className="divide-y divide-border">
              {section.items.map(item => (
                <div key={item.key} className="flex items-center px-4 py-2.5 text-sm">
                  <span className="text-muted-foreground w-52 shrink-0">{item.key}</span>
                  <span className="font-medium text-foreground">{item.value}</span>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function SettingsPage() {
  const { user } = useAuth();
  const isAdmin  = user?.role === 'admin' || user?.permissions?.includes('workers.create');

  return (
    <div className="p-6 space-y-6 max-w-5xl">
      {/* Header */}
      <div>
        <h1 className="text-xl font-bold text-foreground">Settings</h1>
        <p className="text-sm text-muted-foreground mt-0.5">
          System administration — users, roles, locations, and config
        </p>
      </div>

      {/* Access note for non-admins */}
      {!isAdmin && (
        <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
          <AlertTriangle size={15} className="text-amber-600 mt-0.5 shrink-0" />
          <p className="text-sm text-amber-700">
            You have read-only access to this section. Contact an administrator to make changes.
          </p>
        </div>
      )}

      {/* Tabs */}
      <Tabs defaultValue="users">
        <TabsList className="bg-muted/50">
          <TabsTrigger value="users" className="flex items-center gap-1.5">
            <Users size={13} /> Users
          </TabsTrigger>
          <TabsTrigger value="roles" className="flex items-center gap-1.5">
            <Shield size={13} /> Roles &amp; Perms
          </TabsTrigger>
          <TabsTrigger value="locations" className="flex items-center gap-1.5">
            <MapPin size={13} /> Locations
          </TabsTrigger>
          <TabsTrigger value="compliance" className="flex items-center gap-1.5">
            <Percent size={13} /> Compliance
          </TabsTrigger>
          <TabsTrigger value="config" className="flex items-center gap-1.5">
            <Settings2 size={13} /> System Config
          </TabsTrigger>
        </TabsList>

        <TabsContent value="users"      className="mt-5"><UsersTab /></TabsContent>
        <TabsContent value="roles"      className="mt-5"><RolesTab /></TabsContent>
        <TabsContent value="locations"  className="mt-5"><FactoryLocationsTab /></TabsContent>
        <TabsContent value="compliance" className="mt-5"><ComplianceRatesTab /></TabsContent>
        <TabsContent value="config"     className="mt-5"><SystemConfigTab /></TabsContent>
      </Tabs>
    </div>
  );
}
