'use client';

const STATUS_MAP: Record<string, { bg: string; text: string; label?: string }> = {
  // Production / validation
  clean:       { bg: 'bg-green-100',   text: 'text-green-700' },
  flagged:     { bg: 'bg-amber-100',   text: 'text-amber-700' },
  warning:     { bg: 'bg-amber-100',   text: 'text-amber-700' },
  error:       { bg: 'bg-red-100',     text: 'text-red-700' },
  held:        { bg: 'bg-orange-100',  text: 'text-orange-700' },
  // Shift
  adjustment:  { bg: 'bg-indigo-100',  text: 'text-indigo-700' },
  // Payroll
  'below-floor': { bg: 'bg-red-100',  text: 'text-red-700' },
  below_floor:   { bg: 'bg-red-100',  text: 'text-red-700' },
  paid:          { bg: 'bg-[#322E53]/10', text: 'text-[#322E53]' },
  locked:        { bg: 'bg-[#322E53]/10', text: 'text-[#322E53]' },
  released:      { bg: 'bg-green-100',    text: 'text-green-700' },
  open:          { bg: 'bg-blue-100',     text: 'text-blue-700' },
  processing:    { bg: 'bg-amber-100',    text: 'text-amber-700' },
  reversed:      { bg: 'bg-gray-100',     text: 'text-gray-600' },
  // Worker status
  active:        { bg: 'bg-green-100',  text: 'text-green-700' },
  inactive:      { bg: 'bg-amber-100',  text: 'text-amber-700' },
  terminated:    { bg: 'bg-red-100',    text: 'text-red-700' },
  seasonal_off:  { bg: 'bg-gray-100',   text: 'text-gray-600' },
  // Contractor
  suspended:     { bg: 'bg-orange-100', text: 'text-orange-700' },
  expired:       { bg: 'bg-red-100',    text: 'text-red-700' },
  // Advances / loans
  pending:       { bg: 'bg-amber-100',  text: 'text-amber-700' },
  approved:      { bg: 'bg-green-100',  text: 'text-green-700' },
  rejected:      { bg: 'bg-red-100',    text: 'text-red-700' },
  deducted:      { bg: 'bg-gray-100',   text: 'text-gray-600' },
  defaulted:     { bg: 'bg-red-100',    text: 'text-red-700' },
  // Source
  bata_api:      { bg: 'bg-blue-100',   text: 'text-blue-700', label: 'API' },
  manual_supervisor: { bg: 'bg-green-100', text: 'text-green-700', label: 'Manual' },
  manual_backfill:   { bg: 'bg-purple-100', text: 'text-purple-700', label: 'Backfill' },
  // QC
  applied:    { bg: 'bg-gray-100',   text: 'text-gray-600' },
  disputed:   { bg: 'bg-orange-100', text: 'text-orange-700' },
  // Grade
  junior:   { bg: 'bg-gray-100',    text: 'text-gray-600' },
  standard: { bg: 'bg-blue-100',    text: 'text-blue-700' },
  senior:   { bg: 'bg-[#EEC293]/30', text: 'text-[#322E53]' },
  master:   { bg: 'bg-[#322E53]/10', text: 'text-[#322E53]' },
};

interface StatusBadgeProps {
  status: string;
  label?: string;
  size?: 'sm' | 'md';
}

export function StatusBadge({ status, label, size = 'sm' }: StatusBadgeProps) {
  const key = status.toLowerCase().replace(/[\s-]/g, '_');
  const map = STATUS_MAP[key] ?? { bg: 'bg-gray-100', text: 'text-gray-600' };
  const displayLabel = label ?? map.label ?? status.replace(/_/g, ' ').toUpperCase();
  const sizeClass = size === 'md' ? 'px-3 py-1 text-xs' : 'px-2.5 py-0.5 text-[11px]';
  return (
    <span className={`inline-flex items-center rounded-full font-bold tracking-wide ${sizeClass} ${map.bg} ${map.text}`}>
      {displayLabel}
    </span>
  );
}
