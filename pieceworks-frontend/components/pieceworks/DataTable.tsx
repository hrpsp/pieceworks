'use client';

import {
  useReactTable,
  getCoreRowModel,
  getSortedRowModel,
  getPaginationRowModel,
  flexRender,
  type ColumnDef,
  type SortingState,
  type Row,
} from '@tanstack/react-table';
import { useState } from 'react';
import { ChevronUp, ChevronDown, ChevronsUpDown, ChevronLeft, ChevronRight } from 'lucide-react';
import { Skeleton } from '@/components/ui/skeleton';

interface PaginationMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

interface DataTableProps<TData extends object> {
  columns: ColumnDef<TData, any>[];
  data: TData[];
  isLoading?: boolean;
  onRowClick?: (row: Row<TData>) => void;
  pagination?: PaginationMeta;
  onPageChange?: (page: number) => void;
  pageSize?: number;
}

export function DataTable<TData extends object>({
  columns, data, isLoading = false, onRowClick, pagination, onPageChange, pageSize = 20,
}: DataTableProps<TData>) {
  const [sorting, setSorting] = useState<SortingState>([]);

  const table = useReactTable({
    data,
    columns,
    state: { sorting },
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    manualPagination: !!pagination,
    pageCount: pagination?.last_page ?? -1,
  });

  if (isLoading) {
    return (
      <div className="rounded-lg border overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-[#322E53]">
            <tr>
              {columns.map((_, i) => (
                <th key={i} className="px-4 py-3">
                  <Skeleton className="h-4 w-24 bg-white/20" />
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {Array.from({ length: 8 }).map((_, i) => (
              <tr key={i} className={i % 2 === 0 ? 'bg-white' : 'bg-[#F5F4F8]'}>
                {columns.map((_, j) => (
                  <td key={j} className="px-4 py-3">
                    <Skeleton className="h-4 w-full" />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  if (!isLoading && data.length === 0) {
    return (
      <div className="rounded-lg border">
        <table className="w-full text-sm">
          <thead className="bg-[#322E53]">
            <tr>
              {table.getHeaderGroups().map(hg =>
                hg.headers.map(header => (
                  <th key={header.id} className="px-4 py-3 text-left text-white font-semibold text-xs uppercase tracking-wide">
                    {flexRender(header.column.columnDef.header, header.getContext())}
                  </th>
                ))
              )}
            </tr>
          </thead>
        </table>
        <div className="py-16 text-center text-muted-foreground text-sm">
          No records found.
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-lg border overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-[#322E53]">
            {table.getHeaderGroups().map(hg => (
              <tr key={hg.id}>
                {hg.headers.map(header => (
                  <th
                    key={header.id}
                    className="px-4 py-3 text-left text-white font-semibold text-xs uppercase tracking-wide whitespace-nowrap"
                    style={{ width: header.getSize() !== 150 ? header.getSize() : undefined }}
                  >
                    {header.isPlaceholder ? null : (
                      <div
                        className={`flex items-center gap-1 ${header.column.getCanSort() ? 'cursor-pointer select-none' : ''}`}
                        onClick={header.column.getToggleSortingHandler()}
                      >
                        {flexRender(header.column.columnDef.header, header.getContext())}
                        {header.column.getCanSort() && (
                          <span className="ml-1 opacity-70">
                            {header.column.getIsSorted() === 'asc'  ? <ChevronUp size={12} /> :
                             header.column.getIsSorted() === 'desc' ? <ChevronDown size={12} /> :
                             <ChevronsUpDown size={12} />}
                          </span>
                        )}
                      </div>
                    )}
                  </th>
                ))}
              </tr>
            ))}
          </thead>
          <tbody>
            {table.getRowModel().rows.map((row, i) => (
              <tr
                key={row.id}
                className={`border-b last:border-0 transition-colors ${
                  onRowClick ? 'cursor-pointer' : ''
                } ${i % 2 === 0 ? 'bg-white' : 'bg-[#F5F4F8]'} hover:bg-[#EEC293]/15`}
                onClick={() => onRowClick?.(row)}
              >
                {row.getVisibleCells().map(cell => (
                  <td key={cell.id} className="px-4 py-3 text-sm text-foreground whitespace-nowrap">
                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {pagination && (
        <div className="flex items-center justify-between px-4 py-3 bg-white border-t">
          <p className="text-xs text-muted-foreground">
            Showing {((pagination.current_page - 1) * pagination.per_page) + 1}–
            {Math.min(pagination.current_page * pagination.per_page, pagination.total)} of {pagination.total}
          </p>
          <div className="flex items-center gap-1">
            <button
              disabled={pagination.current_page <= 1}
              onClick={() => onPageChange?.(pagination.current_page - 1)}
              className="p-1.5 rounded hover:bg-[#F5F4F8] disabled:opacity-40 disabled:cursor-not-allowed"
            >
              <ChevronLeft size={16} />
            </button>
            <span className="text-xs px-2">
              Page {pagination.current_page} / {pagination.last_page}
            </span>
            <button
              disabled={pagination.current_page >= pagination.last_page}
              onClick={() => onPageChange?.(pagination.current_page + 1)}
              className="p-1.5 rounded hover:bg-[#F5F4F8] disabled:opacity-40 disabled:cursor-not-allowed"
            >
              <ChevronRight size={16} />
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
