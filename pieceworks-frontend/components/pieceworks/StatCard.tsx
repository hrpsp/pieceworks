/**
 * StatCard — PieceWorks shared KPI card
 *
 * Variants
 *   default  – brand-dark icon tray, neutral border
 *   accent   – peach icon tray, peach-tinted border
 *   warning  – amber icon tray, amber-tinted border
 *
 * Icon is optional — omit it for compact metric boxes (e.g. report summaries).
 *
 * Props accept either `label` or `title` (title is a backwards-compat alias).
 *
 * Usage examples:
 *   <StatCard label="Active Workers" value={420} icon={Users} />
 *   <StatCard label="Earnings Today" value="PKR 1,23,456" icon={TrendingUp} accent />
 *   <StatCard label="Failures" value={3} icon={AlertTriangle} warning />
 *   <StatCard label="Top-Up Total" value="PKR 0" />         // no icon, compact
 *   <StatCard label="WoW" value="+12%" trend={{ value: 12, label: 'vs last week' }} icon={BarChart2} />
 */

import type { LucideIcon } from 'lucide-react';

export interface StatCardProps {
  /** Display label — use `label` or the legacy `title` alias */
  label?: string;
  /** @deprecated Use `label` instead */
  title?: string;

  /** Primary metric value */
  value: string | number;

  /** Small sub-label shown below the value */
  sub?: string;

  /** Optional lucide icon (or any React component) */
  icon?: LucideIcon | React.ElementType;

  /** Peach accent variant — highlights a positive / key metric */
  accent?: boolean;

  /** Amber warning variant — highlights an alert metric */
  warning?: boolean;

  /** Optional trend indicator: +/- percentage with a label */
  trend?: { value: number; label: string };

  /** Additional className forwarded to the root element */
  className?: string;
}

export function StatCard({
  label,
  title,
  value,
  sub,
  icon: Icon,
  accent = false,
  warning = false,
  trend,
  className = '',
}: StatCardProps) {
  const displayLabel = label ?? title ?? '';

  // ── Tray + icon colour ───────────────────────────────────────────────────
  const trayClass = warning
    ? 'bg-amber-100'
    : accent
    ? 'bg-brand-peach/20'
    : 'bg-brand-dark/5';

  const iconClass = warning
    ? 'text-amber-600'
    : accent
    ? 'text-brand-peach'
    : 'text-brand-dark';

  // ── Border ───────────────────────────────────────────────────────────────
  const borderClass = warning
    ? 'border-amber-200'
    : accent
    ? 'border-brand-peach/30'
    : 'border-border';

  return (
    <div
      className={`bg-card rounded-xl border ${borderClass} p-5 flex items-start gap-4 ${className}`}
    >
      {/* Icon tray — only rendered when an icon is provided */}
      {Icon && (
        <div className={`p-2.5 rounded-lg shrink-0 ${trayClass}`}>
          <Icon size={18} className={iconClass} />
        </div>
      )}

      {/* Text block */}
      <div className="min-w-0 flex-1">
        <p className="text-xs text-muted-foreground font-medium uppercase tracking-wide truncate">
          {displayLabel}
        </p>

        <p
          className={`text-2xl font-bold mt-0.5 truncate ${
            warning ? 'text-amber-700' : 'text-foreground'
          }`}
        >
          {value}
        </p>

        {sub && (
          <p className="text-xs text-muted-foreground mt-0.5">{sub}</p>
        )}

        {trend && (
          <p
            className={`text-xs font-medium mt-1 ${
              trend.value >= 0 ? 'text-green-600' : 'text-red-500'
            }`}
          >
            {trend.value >= 0 ? '▲' : '▼'}{' '}
            {Math.abs(trend.value)}% {trend.label}
          </p>
        )}
      </div>
    </div>
  );
}
