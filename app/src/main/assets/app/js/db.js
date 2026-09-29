/**
 * Local persistence (IndexedDB) for offline-first play.
 *
 * Stores:
 *   games           — every completed local/online game (PGN + metadata)
 *   puzzle_progress — per-puzzle attempts/solved state (guests keep this too)
 *   lesson_progress — per-lesson completion
 *
 * If IndexedDB is unavailable, an in-memory store keeps the app functional
 * for the current session so offline play never breaks.
 */

const DB_NAME = 'checkmate';
const DB_VERSION = 1;
const STORES = ['games', 'puzzle_progress', 'lesson_progress'];

let dbPromise = null;
let memMaps = null;

function memoryStore(name) {
  if (!memMaps) {
    memMaps = {};
    for (const s of STORES) memMaps[s] = new Map();
  }
  if (!memMaps[name]) memMaps[name] = new Map();
  return memMaps[name];
}

function openDB() {
  if (dbPromise) return dbPromise;
  dbPromise = new Promise((resolve) => {
    let req;
    try {
      if (typeof indexedDB === 'undefined') {
        resolve(null);
        return;
      }
      req = indexedDB.open(DB_NAME, DB_VERSION);
    } catch {
      resolve(null);
      return;
    }
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains('games')) {
        const s = db.createObjectStore('games', { keyPath: 'id' });
        s.createIndex('finishedAt', 'finishedAt');
        s.createIndex('mode', 'mode');
        s.createIndex('result', 'result');
      }
      if (!db.objectStoreNames.contains('puzzle_progress')) {
        const s = db.createObjectStore('puzzle_progress', { keyPath: 'id' });
        s.createIndex('solved', 'solved');
      }
      if (!db.objectStoreNames.contains('lesson_progress')) {
        db.createObjectStore('lesson_progress', { keyPath: 'id' });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => resolve(null);
    req.onblocked = () => resolve(null);
  });
  return dbPromise;
}

/**
 * Run a unit of work against a store.
 * `work(helpers)` may call helpers.put/get/getAll/delete/clear/count; the value
 * returned by `work` is resolved after the transaction completes.
 */
async function run(storeName, mode, work) {
  const db = await openDB().catch(() => null);

  if (!db) {
    // memory fallback
    const map = memoryStore(storeName);
    const helpers = {
      put: (v) => map.set(v.id, structuredCloneSafe(v)),
      get: (k) => map.get(k) ?? null,
      getAll: () => Array.from(map.values()),
      delete: (k) => map.delete(k),
      clear: () => map.clear(),
      count: () => map.size,
    };
    return work(helpers);
  }

  return new Promise((resolve, reject) => {
    let tx;
    try {
      tx = db.transaction(storeName, mode);
    } catch (e) {
      reject(e);
      return;
    }
    const os = tx.objectStore(storeName);
    let pendingReq = null;
    const helpers = {
      put: (v) => { pendingReq = os.put(v); },
      get: (k) => { pendingReq = os.get(k); },
      getAll: () => { pendingReq = os.getAll(); },
      delete: (k) => { pendingReq = os.delete(k); },
      clear: () => { pendingReq = os.clear(); },
      count: () => { pendingReq = os.count(); },
    };

    let result;
    try {
      result = work(helpers);
    } catch (e) {
      reject(e);
      return;
    }

    const finish = () => {
      if (pendingReq && 'result' in pendingReq) {
        // read requests: expose their result once the transaction is done
        let value;
        try { value = pendingReq.result; } catch { value = undefined; }
        resolve(value !== undefined ? value : result);
      } else {
        resolve(result);
      }
    };
    tx.oncomplete = finish;
    tx.onerror = () => reject(tx.error || new Error('transaction failed'));
    tx.onabort = () => reject(tx.error || new Error('transaction aborted'));
  });
}

function structuredCloneSafe(v) {
  try {
    return structuredClone(v);
  } catch {
    return JSON.parse(JSON.stringify(v));
  }
}

// ------------------------------------------------------------ public API

export async function dbPut(store, value) {
  try {
    await run(store, 'readwrite', (h) => h.put(value));
    return value;
  } catch {
    return null;
  }
}

export async function dbGet(store, key) {
  try {
    return await run(store, 'readonly', (h) => h.get(key));
  } catch {
    return null;
  }
}

export async function dbGetAll(store) {
  try {
    const all = await run(store, 'readonly', (h) => h.getAll());
    return Array.isArray(all) ? all : [];
  } catch {
    return [];
  }
}

export async function dbDelete(store, key) {
  try {
    await run(store, 'readwrite', (h) => h.delete(key));
    return true;
  } catch {
    return false;
  }
}

export async function dbCount(store) {
  try {
    const n = await run(store, 'readonly', (h) => h.count());
    return typeof n === 'number' ? n : 0;
  } catch {
    return 0;
  }
}

export async function dbClear(store) {
  try {
    await run(store, 'readwrite', (h) => h.clear());
    return true;
  } catch {
    return false;
  }
}

/** Remove every local record (logout / delete-account / privacy reset). */
export async function dbClearAll() {
  for (const s of STORES) await dbClear(s);
}

// ------------------------------------------------------------- game record

export function makeGameId() {
  return `g_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
}

/** Persist a finished game. Retrying the same id must never duplicate rows. */
export async function saveGame(record) {
  const existing = await dbGet('games', record.id);
  if (existing) return existing;
  const entry = { ...record, savedAt: record.savedAt || Date.now() };
  await dbPut('games', entry);
  return entry;
}

/** @param {{result?:string, mode?:string, limit?:number}} filter */
export async function listGames(filter = {}) {
  let rows = await dbGetAll('games');
  if (filter.result) rows = rows.filter((g) => g.result === filter.result);
  if (filter.mode) rows = rows.filter((g) => g.mode === filter.mode);
  rows.sort((a, b) => (b.finishedAt || 0) - (a.finishedAt || 0));
  return filter.limit ? rows.slice(0, filter.limit) : rows;
}

export function getGame(id) {
  return dbGet('games', id);
}
