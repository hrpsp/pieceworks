/**
 * Polls the offline queue every 30 seconds and reports sync state.
 * Triggers syncQueue() automatically when the browser comes back online.
 */

'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import {
  queueCount,
  syncQueue,
  getLastSynced,
} from '@/lib/offline-queue';

const POLL_INTERVAL_MS = 30_000;

export interface SyncStatus {
  pendingCount: number;
  isSyncing:    boolean;
  lastSynced:   string | null;  // ISO timestamp or null
}

export function useSyncStatus(): SyncStatus {
  const [pendingCount, setPendingCount] = useState(0);
  const [isSyncing,    setIsSyncing]    = useState(false);
  const [lastSynced,   setLastSynced]   = useState<string | null>(null);

  // Avoid overlapping sync attempts
  const syncingRef = useRef(false);

  const refreshCount = useCallback(async () => {
    try {
      const [count, ts] = await Promise.all([queueCount(), getLastSynced()]);
      setPendingCount(count);
      setLastSynced(ts);
    } catch {
      // IndexedDB unavailable (SSR, private browsing restrictions) — ignore
    }
  }, []);

  const runSync = useCallback(async () => {
    if (syncingRef.current) return;
    syncingRef.current = true;
    setIsSyncing(true);

    try {
      const result = await syncQueue();
      setPendingCount(result.remaining);
      if (result.synced > 0) {
        setLastSynced(new Date().toISOString());
      }
    } catch {
      // Network error — will retry on next poll or online event
    } finally {
      setIsSyncing(false);
      syncingRef.current = false;
    }
  }, []);

  // Initial load
  useEffect(() => {
    refreshCount();
  }, [refreshCount]);

  // Periodic polling
  useEffect(() => {
    const id = setInterval(refreshCount, POLL_INTERVAL_MS);
    return () => clearInterval(id);
  }, [refreshCount]);

  // Auto-sync when browser reconnects
  useEffect(() => {
    const handleOnline = () => {
      refreshCount();
      runSync();
    };

    window.addEventListener('online', handleOnline);
    return () => window.removeEventListener('online', handleOnline);
  }, [refreshCount, runSync]);

  return { pendingCount, isSyncing, lastSynced };
}
