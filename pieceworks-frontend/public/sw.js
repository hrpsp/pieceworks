/**
 * PieceWorks Service Worker — Background Sync fallback.
 *
 * When the main thread registers a 'production-sync' sync tag, this worker
 * attempts to flush the offline queue even when the page is closed.
 *
 * The actual sync logic mirrors syncQueue() in lib/offline-queue.ts but uses
 * raw IndexedDB (no ES module imports available in SW scope).
 */

const DB_NAME  = 'pieceworks-offline';
const DB_VER   = 1;
const STORE    = 'production_queue';
const SYNC_TAG = 'production-sync';

// ── Lifecycle ─────────────────────────────────────────────────────────────────

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

// ── Background Sync ───────────────────────────────────────────────────────────

self.addEventListener('sync', event => {
  if (event.tag === SYNC_TAG) {
    event.waitUntil(flushQueue());
  }
});

async function flushQueue() {
  const db      = await openDB();
  const entries = await getAll(db);
  if (!entries.length) return;

  // Retrieve API base URL and token from the first available client
  const clients = await self.clients.matchAll();
  let baseUrl = '';
  let token   = '';

  for (const client of clients) {
    try {
      const url = new URL(client.url);
      baseUrl = url.origin; // Fallback: use the app origin
    } catch { /* ignore */ }
  }

  // Try reading env from the SW scope itself (injected at registration time)
  if (self.__API_URL__) baseUrl = self.__API_URL__;

  for (const entry of entries) {
    try {
      const res = await fetch(`${baseUrl}/api/production/batch`, {
        method:  'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept':       'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify({ records: entry.records }),
      });

      if (res.ok) {
        await deleteEntry(db, entry._localId);
      }
    } catch {
      // Leave in queue for next sync attempt
    }
  }
}

// ── Minimal raw IDB helpers (no idb package in SW) ───────────────────────────

function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VER);

    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE, { keyPath: '_localId', autoIncrement: true });
      }
      if (!db.objectStoreNames.contains('sync_status')) {
        db.createObjectStore('sync_status');
      }
    };

    req.onsuccess = () => resolve(req.result);
    req.onerror   = () => reject(req.error);
  });
}

function getAll(db) {
  return new Promise((resolve, reject) => {
    const tx  = db.transaction(STORE, 'readonly');
    const req = tx.objectStore(STORE).getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror   = () => reject(req.error);
  });
}

function deleteEntry(db, id) {
  return new Promise((resolve, reject) => {
    const tx  = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror    = () => reject(tx.error);
  });
}
