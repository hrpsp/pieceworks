'use client';

import { useState }        from 'react';
import { useCreateWorker } from '@/hooks/useWorkers';
import { Button }          from '@/components/ui/button';
import { Input }           from '@/components/ui/input';
import { Label }           from '@/components/ui/label';
import {
  Dialog, DialogContent, DialogHeader,
  DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import {
  Select, SelectContent, SelectItem,
  SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Loader2 } from 'lucide-react';

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  open:    boolean;
  onClose: () => void;
}

// ── Initial form state ────────────────────────────────────────────────────────

const EMPTY = {
  name:           '',
  cnic:           '',
  grade:          'junior'      as 'junior' | 'standard' | 'senior' | 'master',
  default_shift:  'morning'     as 'morning' | 'afternoon' | 'night',
  join_date:      new Date().toISOString().split('T')[0],
  worker_type:    'bata_direct' as 'bata_direct' | 'contractor' | 'seasonal' | 'trainee',
  payment_method: 'cash'        as 'cash' | 'bank_transfer' | 'easypaisa' | 'jazzcash',
  payment_number: '',
  whatsapp:       '',
  status:         'active'      as 'active' | 'inactive',
};

// ── Component ─────────────────────────────────────────────────────────────────

export function AddWorkerModal({ open, onClose }: Props) {
  const [form,  setForm]  = useState({ ...EMPTY });
  const [error, setError] = useState('');
  const create = useCreateWorker();

  function set(field: keyof typeof EMPTY, value: string) {
    setForm(prev => ({ ...prev, [field]: value }));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');

    if (!form.name.trim())      return setError('Name is required.');
    if (!form.cnic.trim())      return setError('CNIC is required.');
    if (!form.join_date)        return setError('Joining date is required.');

    try {
      await create.mutateAsync({
        name:           form.name.trim(),
        cnic:           form.cnic.trim(),
        grade:          form.grade,
        default_shift:  form.default_shift,
        join_date:      form.join_date,
        worker_type:    form.worker_type,
        payment_method: form.payment_method,
        payment_number: form.payment_number || undefined,
        whatsapp:       form.whatsapp       || undefined,
        status:         form.status,
      });
      setForm({ ...EMPTY });
      onClose();
    } catch (err: unknown) {
      const msg = (err as { data?: { message?: string } })?.data?.message
               ?? (err as { message?: string })?.message
               ?? 'Failed to create worker.';
      setError(msg);
    }
  }

  function handleClose() {
    setForm({ ...EMPTY });
    setError('');
    onClose();
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Add New Worker</DialogTitle>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4 py-2">

          {/* Name */}
          <div className="space-y-1.5">
            <Label htmlFor="w-name">Full Name <span className="text-red-500">*</span></Label>
            <Input
              id="w-name"
              placeholder="e.g. Muhammad Ahmed"
              value={form.name}
              onChange={e => set('name', e.target.value)}
            />
          </div>

          {/* CNIC */}
          <div className="space-y-1.5">
            <Label htmlFor="w-cnic">CNIC <span className="text-red-500">*</span></Label>
            <Input
              id="w-cnic"
              placeholder="35201-1234567-1"
              value={form.cnic}
              onChange={e => set('cnic', e.target.value)}
            />
          </div>

          {/* Grade + Shift */}
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label>Grade <span className="text-red-500">*</span></Label>
              <Select value={form.grade} onValueChange={v => set('grade', v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="junior">Junior</SelectItem>
                  <SelectItem value="standard">Standard</SelectItem>
                  <SelectItem value="senior">Senior</SelectItem>
                  <SelectItem value="master">Master</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label>Shift <span className="text-red-500">*</span></Label>
              <Select value={form.default_shift} onValueChange={v => set('default_shift', v as typeof form.default_shift)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="morning">Morning</SelectItem>
                  <SelectItem value="afternoon">Afternoon</SelectItem>
                  <SelectItem value="night">Night</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          {/* Worker type + Status */}
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label>Worker Type</Label>
              <Select value={form.worker_type} onValueChange={v => set('worker_type', v as typeof form.worker_type)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="bata_direct">Bata Direct</SelectItem>
                  <SelectItem value="contractor">Contractor</SelectItem>
                  <SelectItem value="seasonal">Seasonal</SelectItem>
                  <SelectItem value="trainee">Trainee</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label>Status</Label>
              <Select value={form.status} onValueChange={v => set('status', v as typeof form.status)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="inactive">Inactive</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          {/* Joining Date */}
          <div className="space-y-1.5">
            <Label htmlFor="w-join">Joining Date <span className="text-red-500">*</span></Label>
            <Input
              id="w-join"
              type="date"
              value={form.join_date}
              onChange={e => set('join_date', e.target.value)}
            />
          </div>

          {/* Payment */}
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label>Payment Method</Label>
              <Select value={form.payment_method} onValueChange={v => set('payment_method', v as typeof form.payment_method)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="cash">Cash</SelectItem>
                  <SelectItem value="bank_transfer">Bank Transfer</SelectItem>
                  <SelectItem value="easypaisa">Easypaisa</SelectItem>
                  <SelectItem value="jazzcash">JazzCash</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="w-pnum">Account / Number</Label>
              <Input
                id="w-pnum"
                placeholder="Account or mobile no."
                value={form.payment_number}
                onChange={e => set('payment_number', e.target.value)}
              />
            </div>
          </div>

          {/* WhatsApp */}
          <div className="space-y-1.5">
            <Label htmlFor="w-wa">WhatsApp Number</Label>
            <Input
              id="w-wa"
              placeholder="+923001234567"
              value={form.whatsapp}
              onChange={e => set('whatsapp', e.target.value)}
            />
          </div>

          {/* Error */}
          {error && (
            <p className="text-sm text-red-500 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
              {error}
            </p>
          )}

          <DialogFooter className="pt-2">
            <Button type="button" variant="outline" onClick={handleClose} disabled={create.isPending}>
              Cancel
            </Button>
            <Button
              type="submit"
              className="bg-brand-dark hover:bg-brand-mid text-white"
              disabled={create.isPending}
            >
              {create.isPending
                ? <><Loader2 size={14} className="animate-spin mr-1.5" /> Creating…</>
                : 'Create Worker'}
            </Button>
          </DialogFooter>

        </form>
      </DialogContent>
    </Dialog>
  );
}
