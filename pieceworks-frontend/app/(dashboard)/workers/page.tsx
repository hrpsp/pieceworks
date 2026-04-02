'use client';

import { useState }        from 'react';
import { useRouter }       from 'next/navigation';
import { useWorkers, type WorkerFilters } from '@/hooks/useWorkers';
import { AddWorkerModal }  from '@/components/pieceworks/AddWorkerModal';
import { Input }           from '@/components/ui/input';
import { Button }          from '@/components/ui/button';
import { Badge }           from '@/components/ui/badge';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Search, ChevronLeft, ChevronRight, UserPlus } from 'lucide-react';

const STATUS_COLORS: Record<string, string> = {
  active:     'bg-green-100 text-green-700',
  inactive:   'bg-amber-100 text-amber-700',
  terminated: 'bg-red-100 text-red-700',
};

const GRADE_COLORS: Record<string, string> = {
  A: 'bg-brand-peach/20 text-brand-dark',
  B: 'bg-brand-salmon/20 text-brand-dark',
  C: 'bg-muted text-muted-foreground',
};

export default function WorkersPage() {
  const router = useRouter();
  const [search,    setSearch]    = useState('');
  const [status,    setStatus]    = useState<string>('all');
  const [shift,     setShift]     = useState<string>('all');
  const [page,      setPage]      = useState(1);
  const [inputVal,  setInputVal]  = useState('');
  const [showAdd,   setShowAdd]   = useState(false);

  const filters: WorkerFilters = {
    page,
    per_page: 20,
    ...(search           && { search }),
    ...(status !== 'all' && { status: status as WorkerFilters['status'] }),
    ...(shift  !== 'all' && { shift:  shift  as WorkerFilters['shift']  }),
  };

  const { data, isPending, isError } = useWorkers(filters);
  const workers = data?.data  ?? [];
  const meta    = data?.meta;

  function applySearch() {
    setSearch(inputVal);
    setPage(1);
  }

  function handleStatusChange(val: string) {
    setStatus(val);
    setPage(1);
  }

  function handleShiftChange(val: string) {
    setShift(val);
    setPage(1);
  }

  return (
    <div className="p-6 space-y-5 max-w-7xl mx-auto">

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Workers</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            {meta ? `${meta.total} workers total` : 'Loading…'}
          </p>
        </div>
        <Button
          className="bg-brand-dark hover:bg-brand-mid text-white gap-2"
          onClick={() => setShowAdd(true)}
        >
          <UserPlus size={15} /> Add Worker
        </Button>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3">
        <div className="relative flex-1 min-w-48">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Search name or CNIC…"
            className="pl-8 h-9"
            value={inputVal}
            onChange={e => setInputVal(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && applySearch()}
          />
        </div>

        <Select value={status} onValueChange={handleStatusChange}>
          <SelectTrigger className="h-9 w-36">
            <SelectValue placeholder="Status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
            <SelectItem value="terminated">Terminated</SelectItem>
          </SelectContent>
        </Select>

        <Select value={shift} onValueChange={handleShiftChange}>
          <SelectTrigger className="h-9 w-36">
            <SelectValue placeholder="Shift" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All shifts</SelectItem>
            <SelectItem value="morning">Morning</SelectItem>
            <SelectItem value="evening">Evening</SelectItem>
            <SelectItem value="night">Night</SelectItem>
          </SelectContent>
        </Select>

        <Button variant="outline" size="sm" onClick={applySearch} className="h-9">
          Search
        </Button>
      </div>

      {/* Table */}
      <div className="bg-card rounded-xl border border-border overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border bg-muted/50">
              {['Name', 'CNIC', 'Grade', 'Shift', 'Contractor', 'Status'].map(h => (
                <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isPending ? (
              Array.from({ length: 8 }).map((_, i) => (
                <tr key={i} className="border-b border-border last:border-0">
                  {Array.from({ length: 6 }).map((_, j) => (
                    <td key={j} className="px-4 py-3">
                      <Skeleton className="h-4 w-24" />
                    </td>
                  ))}
                </tr>
              ))
            ) : isError ? (
              <tr>
                <td colSpan={6} className="px-4 py-12 text-center text-muted-foreground">
                  Failed to load workers. Please try again.
                </td>
              </tr>
            ) : workers.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-12 text-center text-muted-foreground">
                  No workers match the current filters.
                </td>
              </tr>
            ) : (
              workers.map(worker => (
                <tr
                  key={worker.id}
                  className="border-b border-border last:border-0 hover:bg-muted/30 cursor-pointer transition-colors"
                  onClick={() => router.push(`/workers/${worker.id}`)}
                >
                  <td className="px-4 py-3 font-medium text-foreground">{worker.name}</td>
                  <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{worker.cnic}</td>
                  <td className="px-4 py-3">
                    <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${GRADE_COLORS[worker.grade] ?? 'bg-muted text-muted-foreground'}`}>
                      {worker.grade}
                    </span>
                  </td>
                  <td className="px-4 py-3 capitalize text-muted-foreground text-xs">{worker.default_shift}</td>
                  <td className="px-4 py-3 text-muted-foreground text-xs">
                    {worker.contractor_id ? `#${worker.contractor_id}` : '—'}
                  </td>
                  <td className="px-4 py-3">
                    <Badge className={`text-xs capitalize border-0 ${STATUS_COLORS[worker.status] ?? ''}`}>
                      {worker.status}
                    </Badge>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline" size="sm"
              onClick={() => setPage(p => p - 1)}
              disabled={page <= 1}
              className="h-8 w-8 p-0"
            >
              <ChevronLeft size={14} />
            </Button>
            <span className="px-2">{page} / {meta.last_page}</span>
            <Button
              variant="outline" size="sm"
              onClick={() => setPage(p => p + 1)}
              disabled={page >= meta.last_page}
              className="h-8 w-8 p-0"
            >
              <ChevronRight size={14} />
            </Button>
          </div>
        </div>
      )}

      {/* Add Worker Modal */}
      <AddWorkerModal open={showAdd} onClose={() => setShowAdd(false)} />
    </div>
  );
}
