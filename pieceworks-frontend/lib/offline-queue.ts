/**
 * IndexedDB-backed offline queue for production sessions.
 *
 * Uses the 'idb' package for a clean promise-based API.
 * DB: 'pieceworks-offline'
 *   - production_queue: queued session batches (auto-increment key)
 *   - sync_status:      singleton sync metadata
 */

import { openDB, type IDBPDatabase } from 'idb';
import { getToken } from '@/lib/api-client';
import type { BatchProductionRecord } from '@/hooks/useProduction';

const DB_NAME = 'pieceworks-offline';
const DB_VER  = 1;

// ── Types ─────────────────────────────────────────────────────────────────────

/** A batch of production records saved as a single unit for offline queuing. */
export interface ProductionSession {
  /** Auto-assigned by IndexedDB — undefined until persisted. */
  _localId?:   number;
  records:     BatchProductionRecord[];
  queued_at:   string;
  retry_count: number;
}

export interface SyncResult {
  synced:    number;
  failed:    number;
  remaining: number;
}

interface SyncStatusRecord {
  last_synced:  string | null;
  total_synced: number;
}

// ── DB init ───────────────────────────────────────────────────────────────────

export function initDB(): Promise<IDBPDatabase> {
  return openDB(DB_NAME, DB_VER, {
    upgrade(db) {
      if (!db.objectStoreNames.contains('production_queue')) {
        db.createObjectStore('production_queue', {
          keyPath:       '_localId',
          autoIncrement: true,
        });
      }
      if (!db.objectStoreNames.contains('sync_status')) {
        db.createObjectStore('sync_status');
      }
    },
  });
}

// ── Queue operations ──────────────────────────────────────────────────────────

/**
 * Persist a production session to the offline queue.
 * Returns the auto-assigned local ID.
 */
export async function saveToQueue(
  sessionData: Pick<ProductionSession, 'records'>
): Promise<number> {
  const db    = await initDB();
  const entry = {
    records:     sessionData.records,
    queued_at:   new Date().toISOString(),
    retry_count: 0,
  };
  // idb returns the generated key; cast is safe with autoIncrement number keys
  return db.add('production_queue', entry) as Promise<number>;
}

/** Return all sessions currently in the offline queue. */
export async function getQueue(): Promise<ProductionSession[]> {
  const db = await initDB();
  return db.getAll('production_queue');
}

/** Remove a single session from the queue after a successful sync. */
export async function removeFromQueue(id: number): Promise<void> {
  const db = await initDB();
  return db.delete('production_queue', id);
}

/** Remove every session from the queue. */
export async function clearQueue(): Promise<void> {
  const db = await initDB();
  return db.clear('production_queue');
}

/** Number of sessions currently queued. */
export async function queueCount(): Promise<number> {
  const db = await initDB();
  return db.count('production_queue');
}

// ── Sync ──────────────────────────────────────────────────────────────────────

/**
 * Attempt to POST every queued session to /api/production/batch.
 *
 * - Successful sessions are removed from the queue.
 * - Failed sessions remain and their retry_count is incremented.
 * - Returns a summary of { synced, failed, remaining }.
 */
export async function syncQueue(): Promise<SyncResult> {
  const db      = await initDB();
  const entries = await db.getAll('production_queue') as ProductionSession[];

  let synced = 0;
  let failed = 0;

  const baseUrl = process.env.NEXT_PUBLIC_API_URL ?? '';
  const token   = getToken();

  const headers: HeadersInit = {
    'Accept':       'application/json',
    'Content-Type': 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };

  for (const entry of entries) {
    try {
      const response = await fetch(`${baseUrl}/production/batch`, {
        method: 'POST',
        headers,
        body:   JSON.stringify({ records: entry.records }),
      });

      if (response.ok) {
        await db.delete('production_queue', entry._localId!);
        synced++;
      } else {
        await db.put('production_queue', {
          ...entry,
          retry_count: entry.retry_count + 1,
        });
        failed++;
      }
    } catch {
      await db.put('production_queue', {
        ...entry,
        retry_count: entry.retry_count + 1,
      });
      failed++;
    }
  }

  // Persist sync metadata
  const statusEntry: SyncStatusRecord = {
    last_synced:  synced > 0 ? new Date().toISOString() : null,
    total_synced: synced,
  };
  await db.put('sync_status', statusEntry, 'status');

  const remaining = await db.count('production_queue');
  return { synced, failed, remaining };
}

/** Read the last-synced timestamp from the sync_status store. */
export async function getLastSynced(): Promise<string | null> {
  const db     = await initDB();
  const status = await db.get('sync_status', 'status') as SyncStatusRecord | undefined;
  return status?.last_synced ?? null;
}
