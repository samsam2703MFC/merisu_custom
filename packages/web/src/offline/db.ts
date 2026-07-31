/**
 * Mini-couche IndexedDB, sans dépendance externe.
 *
 * Sert la file d'attente hors-ligne (§2) : le consultant compte debout au poste,
 * parfois sans réseau ; ses saisies doivent survivre à une fermeture d'onglet.
 * localStorage ne conviendrait pas — les photos dépassent vite son quota.
 */

const DB_NAME = 'merisu-offline';
const DB_VERSION = 1;
export const QUEUE_STORE = 'queue';
export const CACHE_STORE = 'cache';

let dbPromise: Promise<IDBDatabase> | null = null;

function openDb(): Promise<IDBDatabase> {
  if (dbPromise) return dbPromise;

  dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(QUEUE_STORE)) {
        db.createObjectStore(QUEUE_STORE, { keyPath: 'id', autoIncrement: true });
      }
      if (!db.objectStoreNames.contains(CACHE_STORE)) {
        db.createObjectStore(CACHE_STORE);
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });

  return dbPromise;
}

function tx<T>(
  storeName: string,
  mode: IDBTransactionMode,
  action: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
  return openDb().then(
    (db) =>
      new Promise<T>((resolve, reject) => {
        const transaction = db.transaction(storeName, mode);
        const request = action(transaction.objectStore(storeName));
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
      }),
  );
}

export const idb = {
  add<T>(store: string, value: T): Promise<IDBValidKey> {
    return tx(store, 'readwrite', (s) => s.add(value as unknown as object));
  },
  put<T>(store: string, value: T, key?: IDBValidKey): Promise<IDBValidKey> {
    return tx(store, 'readwrite', (s) => s.put(value as unknown as object, key));
  },
  get<T>(store: string, key: IDBValidKey): Promise<T | undefined> {
    return tx(store, 'readonly', (s) => s.get(key) as IDBRequest<T | undefined>);
  },
  getAll<T>(store: string): Promise<T[]> {
    return tx(store, 'readonly', (s) => s.getAll() as IDBRequest<T[]>);
  },
  delete(store: string, key: IDBValidKey): Promise<undefined> {
    return tx(store, 'readwrite', (s) => s.delete(key) as IDBRequest<undefined>);
  },
  clear(store: string): Promise<undefined> {
    return tx(store, 'readwrite', (s) => s.clear() as IDBRequest<undefined>);
  },
};
