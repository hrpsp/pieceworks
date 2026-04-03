'use client';

import { useState, useMemo }   from 'react';
import { useRouter }            from 'next/navigation';
import { useQuery }             from '@tanstack/react-query';
import { useWorkers, type WorkerFilters } from '@/hooks/useWorkers';
import { apiClient }            from '@/lib/api-client';
import { AddWorkerModal }       from '@/components/pieceworks/AddWorkerModal';
import { Input }                from '@/components/ui/input';
import { Button }               from '@/components/ui/button';
import { Badge }                from '@/components/ui/badge';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Skeleton }             from '@/components/ui/skeleton';
import {
  Search, ChevronLeft, ChevronRight, UserPlus, Download, X,
} from 'lucide-react';

// ── Status / grade colours ─────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
  active:     'bg-green-100 text-green-700',
  inactive:   'bg-amber-100 text-amber-700',
  terminated: 'bg-red-100   text-red-700',
};

const GRADE_COLORS: Record<string, string> = {
  A: 'bg-[#322E53]/10 text-[#322E53]',
  B: 'bg-blue-100    text-blue-700',
  C: 'bg-amber-100   text-amber-700',
  D: 'bg-slate-100   text-slate-500',
};

// ── CSV export utility ────────────────────────────────────────────────────────

function exportWorkersCSV(
  workers: any[],
  contractorMap: Map<number, string>
) {
  const headers = ['ID', 'Name', 'CNIC', 'Grade', 'Shift', 'Contractor', 'Status'];
  const rows = workers.map(w => [
    w.id,
    `"${w.name}"`,
    w.cnic ?? '',
    w.grade ?? '',
    w.default_shift ?? '',
    `"${contractorMap.get(w.contractor_id) ?? (w.contractor_id ? `#${w.contractor_id}` : '—')}"`,
    w.status ?? '',
  ]);

  const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `workers-${new Date().toISOString().split('T')[0]}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function WorkersPage() {
  const router = useRouter();
  const [search,   setSearch]   = useState('');
  const [status,   setStatus]   = useState<string>('all');
  const [shift,    setShift]    = useState<string>('all');
  const [grade,    setGrade]    = useState<string>('all');
  const [page,     setPage]     = useState(1);
  const [inputVal, setInputVal] = useState('');
  const [showAdd,  setShowAdd]  = useState(false);

  // Contractor lookup for name display
  const contractorsQ = useQuery({
    queryKey: ['contractors-lookup'],
    queryFn:  () => apiClient.get<any>('/contractors'),
    staleTime: 10 * 60 * 1000,
  });
  const contractorMap = useMemo<Map<number, string>>(() => {
    const list: any[] = (contractorsQ.data as any)?.data?.data ?? [];
    return new Map(list.map(c => [c.id, c.name]));
  }, [contractorsQ.data]);

  const filters: WorkerFilters = {
    page,
    per_page: 20,
    ...(search            && { search }),
    ...(status !== 'all'  && { status: status as WorkerFilters['status'] }),
    ...(shift  !== 'all'  && { shift:  shift  as WorkerFilters['shift']  }),
    ...(grade  !== 'all'  && { grade }),
  };

  const { data, isPending, isError } = useWorkers(filters);
  const workers  = data?.data ?? [];
  const meta     = data?.meta;

  // ── Helpers ──────────────────────────────────────────────────────────────────

  function applySearch() { setSearch(inputVal); setPage(1); }
  function clearSearch()  { setSearch(''); setInputVal(''); setPage(1); }

  function handleStatusChange(val: string) { setStatus(val); setPage(1); }
  function handleShiftChange (val: string) { setShift(val);  setPage(1); }
  function handleGradeChange (val: string) { setGrade(val);  setPage(1); }

  const hasFilters = search || status !== 'all' || shift !== 'all' || grade !== 'all';

  function clearAllFilters() {
    setSearch(''); setInputVal('');
    setStatus('all'); setShift('all'); setGrade('all');
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
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="gap-1.5 h-9"
            disabled={workers.length === 0}
            onClick={() => exportWorkersCSV(workers, contractorMap)}
            title="Export current page to CSV"
          >
            <Download size={14}/> Export CSV
          </Button>
          <Button
            className="bg-[#322E53] hover:bg-[#49426E] text-white gap-2 h-9"
            size="sm"
            onClick={() => setShowAdd(true)}
          >
            <UserPlus size={15}/> Add Worker
          </Button>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3 items-end">
        {/* Search */}
        <div className="relative flex-1 min-w-48">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"/>
          <Input
            placeholder="Search name or CNIC…"
            className="pl-8 pr-8 h-9"
            value={inputVal}
            onChange={e => setInputVal(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && applySearch()}
          />
          {inputVal && (
            <button
              onClick={clearSearch}
              className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
            >
              <X size={13}/>
            </button>
          )}
        </div>

        {/* Status */}
        <Select value={status} onValueChange={handleStatusChange}>
          <SelectTrigger className="h-9 w-36">
            <SelectValue placeholder="Status"/>
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
            <SelectItem value="terminated">Terminated</SelectItem>
          </SelectContent>
        </Select>

        {/* Grade */}
        <Select value={grade} onValueChange={handleGradeChange}>
          <SelectTrigger className="h-9 w-32">
            <SelectValue placeholder="Grade"/>
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All grades</SelectItem>
            <SelectItem value="A">Grade A</SelectItem>
            <SelectItem value="B">Grade B</SelectItem>
            <SelectItem value="C">Grade C</SelectItem>
            <SelectItem value="D">Grade D</SelectItem>
          </SelectContent>
        </Select>

        {/* Shift */}
        <Select value={shift} onValueChange={handleShiftChange}>
          <SelectTrigger className="h-9 w-36">
            <SelectValue placeholder="Shift"/>
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

        {hasFilters && (
          <Button
            variant="ghost" size="sm"
            onClick={clearAllFilters}
            className="h-9 text-xs text-muted-foreground gap-1"
          >
            <X size={12}/> Clear filters
          </Button>
        )}
      </div>

      {/* Active filter chips */}
      {hasFilters && (
        <div className="flex flex-wrap gap-1.5">
          {search && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-[#322E53]/10 text-[#322E53] text-xs font-medium">
              Search: &ldquo;{search}&rdquo;
              <button onClick={clearSearch}><X size={10}/></button>
            </span>
          )}
          {status !== 'all' && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-[#322E53]/10 text-[#322E53] text-xs font-medium capitalize">
              Status: {status}
              <button onClick={() => handleStatusChange('all')}><X size={10}/></button>
            </span>
          )}
          {grade !== 'all' && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-[#322E53]/10 text-[#322E53] text-xs font-medium">
              Grade: {grade}
              <button onClick={() => handleGradeChange('all')}><X size={10}/></button>
            </span>
          )}
          {shift !== 'all' && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-[#322E53]/10 text-[#322E53] text-xs font-medium capitalize">
              Shift: {shift}
              <button onClick={() => handleShiftChange('all')}><X size={10}/></button>
            </span>
          )}
        </div>
      )}

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
                      <Skeleton className="h-4 w-24"/>
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
                <td colSpan={6} className="px-4 py-12 text-center">
                  <p className="text-muted-foreground text-sm">No workers match the current filters.</p>
                  {hasFilters && (
                    <button
                      onClick={clearAllFilters}
                      className="mt-2 text-xs text-[#322E53] hover:underline"
                    >
                      Clear all filters
                    </button>
                  )}
                </td>
              </tr>
            ) : (
              workers.map(worker => {
                const contractorName = worker.contractor_id
                  ? (contractorMap.get(worker.contractor_id) ?? `#${worker.contractor_id}`)
                  : '—';
                return (
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
                      {contractorName}
                    </td>
                    <td className="px-4 py-3">
                      <Badge className={`text-xs capitalize border-0 ${STATUS_COLORS[worker.status] ?? ''}`}>
                        {worker.status}
                      </Badge>
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <span className="text-xs text-muted-foreground">
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline" size="sm"
              onClick={() => setPage(p => p - 1)}
              disabled={page <= 1}
              className="h-8 w-8 p-0"
            >
              <ChevronLeft size={14}/>
            </Button>
            <span className="text-xs px-2">{page} / {meta.last_page}</span>
            <Button
              variant="outline" size="sm"
              onClick={() => setPage(p => p + 1)}
              disabled={page >= meta.last_page}
              className="h-8 w-8 p-0"
            >
              <ChevronRight size={14}/>
            </Button>
          </div>
        </div>
      )}

      {/* Add Worker Modal */}
      <AddWorkerModal open={showAdd} onClose={() => setShowAdd(false)}/>
    </div>
  );
}
