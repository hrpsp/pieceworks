'use client';

import { useState, useEffect } from 'react';
import { WifiOff, CloudUpload, CheckCircle2, Loader2 } from 'lucide-react';
import { useSyncStatus } from '@/hooks/useSyncStatus';

/**
 * Sticky banner that reflects the current online / queue state.
 *
 * States (priority order):
 *  1. Offline                    → 🔴 red banner
 *  2. Online + entries syncing   → 🟡 amber banner + spinner
 *  3. Online + entries pending   → 🟡 amber banner
 *  4. All synced (brief flash)   → 🟢 green banner (auto-hides after 4 s)
 *  5. Idle online / no queue     → nothing rendered
 */
export function OfflineIndicator() {
  const [isOnline, setIsOnline]   = useState(true);
  const [showSynced, setShowSynced] = useState(false);
  const { pendingCount, isSyncing, lastSynced } = useSyncStatus();

  // Track online / offline
  useEffect(() => {
    const update = () => setIsOnline(navigator.onLine);
    update();
    window.addEventListener('online',  update);
    window.addEventListener('offline', update);
    return () => {
      window.removeEventListener('online',  update);
      window.removeEventListener('offline', update);
    };
  }, []);

  // Flash the "all synced" banner briefly after a successful sync
  useEffect(() => {
    if (isOnline && pendingCount === 0 && lastSynced) {
      setShowSynced(true);
      const id = setTimeout(() => setShowSynced(false), 4_000);
      return () => clearTimeout(id);
    }
  }, [isOnline, pendingCount, lastSynced]);

  // ── Offline ──────────────────────────────────────────────────────────────
  if (!isOnline) {
    return (
      <Banner color="red">
        <WifiOff size={14} className="shrink-0" />
        <span>Offline — production entries will be queued</span>
      </Banner>
    );
  }

  // ── Syncing ──────────────────────────────────────────────────────────────
  if (isSyncing && pendingCount > 0) {
    return (
      <Banner color="amber">
        <Loader2 size={14} className="shrink-0 animate-spin" />
        <span>{pendingCount} {pendingCount === 1 ? 'entry' : 'entries'} queued — syncing…</span>
      </Banner>
    );
  }

  // ── Pending but online ───────────────────────────────────────────────────
  if (pendingCount > 0) {
    return (
      <Banner color="amber">
        <CloudUpload size={14} className="shrink-0" />
        <span>
          {pendingCount} {pendingCount === 1 ? 'entry' : 'entries'} queued —
          will sync when ready
        </span>
      </Banner>
    );
  }

  // ── All synced flash ─────────────────────────────────────────────────────
  if (showSynced) {
    return (
      <Banner color="green">
        <CheckCircle2 size={14} className="shrink-0" />
        <span>All entries synced</span>
      </Banner>
    );
  }

  return null;
}

// ── Internal Banner primitive ─────────────────────────────────────────────────

function Banner({
  color,
  children,
}: {
  color: 'red' | 'amber' | 'green';
  children: React.ReactNode;
}) {
  const styles: Record<typeof color, string> = {
    red:   'bg-red-50   border-red-200   text-red-800',
    amber: 'bg-amber-50 border-amber-200 text-amber-800',
    green: 'bg-green-50 border-green-200 text-green-800',
  };

  return (
    <div
      className={`flex items-center gap-2 px-4 py-2 text-sm border rounded-lg ${styles[color]}`}
      role="status"
      aria-live="polite"
    >
      {children}
    </div>
  );
}
