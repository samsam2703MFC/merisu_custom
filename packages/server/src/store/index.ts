/** Fabrique du store selon la configuration (`STORE=memory|postgres`). */

import type { ServerConfig } from '../config.js';
import { MemoryStore } from './memory.js';
import { PostgresStore } from './postgres.js';
import type { Store } from './types.js';

export async function createStore(config: ServerConfig): Promise<Store> {
  const store: Store =
    config.store === 'postgres'
      ? new PostgresStore(config.databaseUrl!)
      : new MemoryStore(config.memorySnapshotFile);

  await store.init();
  return store;
}

export { MemoryStore, PostgresStore };
export * from './types.js';
export * from './defaults.js';
