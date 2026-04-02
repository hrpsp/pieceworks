import { LucideIcon } from 'lucide-react';

interface StatCardProps {
  icon: LucideIcon;
  title: string;
  value: string | number;
  sub?: string;
  accent?: boolean;
  trend?: { value: number; label: string };
}

export function StatCard({ icon: Icon, title, value, sub, accent = false, trend }: StatCardProps) {
  return (
    <div className="bg-white rounded-xl border border-border shadow-sm p-5 flex items-start gap-4">
      <div className={`p-2.5 rounded-lg flex-shrink-0 ${accent ? 'bg-[#EEC293]/20' : 'bg-[#322E53]/8'}`}
           style={!accent ? { backgroundColor: 'rgba(50,46,83,0.08)' } : {}}>
        <Icon size={18} className={accent ? 'text-[#EEC293]' : 'text-[#322E53]'} />
      </div>
      <div className="min-w-0">
        <p className="text-xs text-muted-foreground font-medium uppercase tracking-wide truncate">{title}</p>
        <p className="text-2xl font-bold text-foreground mt-0.5 truncate">{value}</p>
        {sub && <p className="text-xs text-muted-foreground mt-0.5">{sub}</p>}
        {trend && (
          <p className={`text-xs font-medium mt-1 ${trend.value >= 0 ? 'text-green-600' : 'text-red-500'}`}>
            {trend.value >= 0 ? '▲' : '▼'} {Math.abs(trend.value)}% {trend.label}
          </p>
        )}
      </div>
    </div>
  );
}
