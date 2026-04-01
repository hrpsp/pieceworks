'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useState, useEffect } from 'react';
import {
  LayoutDashboard,
  Users,
  ClipboardList,
  Wallet,
  Building2,
  CreditCard,
  BarChart2,
  ChevronLeft,
  ChevronRight,
  LogOut,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { clearToken } from '@/lib/api-client';

const NAV_ITEMS = [
  { href: '/dashboard',   label: 'Dashboard',   icon: LayoutDashboard },
  { href: '/workers',     label: 'Workers',     icon: Users },
  { href: '/production',  label: 'Production',  icon: ClipboardList },
  { href: '/payroll',     label: 'Payroll',     icon: Wallet },
  { href: '/contractor',  label: 'Contractors', icon: Building2 },
  { href: '/rate-cards',  label: 'Rate Cards',  icon: CreditCard },
  { href: '/reports',     label: 'Reports',     icon: BarChart2 },
];

const COLLAPSED_KEY = 'pw_sidebar_collapsed';

export function Sidebar() {
  const pathname  = usePathname();
  const router    = useRouter();
  const [collapsed, setCollapsed] = useState(false);

  // Restore persisted collapse state
  useEffect(() => {
    const stored = localStorage.getItem(COLLAPSED_KEY);
    if (stored === 'true') setCollapsed(true);
  }, []);

  function toggle() {
    const next = !collapsed;
    setCollapsed(next);
    localStorage.setItem(COLLAPSED_KEY, String(next));
  }

  function handleLogout() {
    clearToken();
    router.push('/login');
  }

  return (
    <aside
      className={cn(
        'flex flex-col h-screen bg-brand-dark text-white transition-all duration-200 shrink-0',
        collapsed ? 'w-14' : 'w-52'
      )}
    >
      {/* Logo */}
      <div className="flex items-center h-14 px-3 border-b border-white/10">
        <div className="flex items-center gap-2 min-w-0">
          <div className="w-7 h-7 rounded-md bg-brand-peach flex items-center justify-center shrink-0">
            <span className="text-brand-dark font-bold text-xs">PW</span>
          </div>
          {!collapsed && (
            <span className="font-bold text-sm tracking-wide truncate">
              PieceWorks
            </span>
          )}
        </div>
      </div>

      {/* Nav items */}
      <nav className="flex-1 py-3 px-2 space-y-0.5 overflow-y-auto">
        {NAV_ITEMS.map(({ href, label, icon: Icon }) => {
          const active = pathname === href || pathname.startsWith(href + '/');
          return (
            <Link
              key={href}
              href={href}
              className={cn(
                'flex items-center gap-3 px-2 py-2 rounded-md text-sm font-medium transition-colors',
                active
                  ? 'bg-brand-peach/20 text-brand-peach'
                  : 'text-white/70 hover:bg-white/10 hover:text-white'
              )}
              title={collapsed ? label : undefined}
            >
              <Icon
                size={18}
                className={cn('shrink-0', active ? 'text-brand-peach' : '')}
              />
              {!collapsed && <span className="truncate">{label}</span>}
            </Link>
          );
        })}
      </nav>

      {/* Bottom: logout + collapse toggle */}
      <div className="border-t border-white/10 p-2 space-y-0.5">
        <button
          onClick={handleLogout}
          className="w-full flex items-center gap-3 px-2 py-2 rounded-md text-sm text-white/60 hover:bg-white/10 hover:text-white transition-colors"
          title={collapsed ? 'Log out' : undefined}
        >
          <LogOut size={16} className="shrink-0" />
          {!collapsed && <span>Log out</span>}
        </button>

        <button
          onClick={toggle}
          className="w-full flex items-center justify-center px-2 py-2 rounded-md text-white/40 hover:bg-white/10 hover:text-white transition-colors"
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          {collapsed ? <ChevronRight size={16} /> : <ChevronLeft size={16} />}
        </button>
      </div>
    </aside>
  );
}
