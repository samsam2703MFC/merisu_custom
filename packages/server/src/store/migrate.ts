/**
 * Applique le schéma PostgreSQL (script idempotent).
 * Usage : `DATABASE_URL=postgres://… npm run migrate -w @merisu/server`
 */

import { PostgresStore } from './postgres.js';

const url = process.env.DATABASE_URL;
if (!url) {
  console.error('DATABASE_URL est requis pour appliquer les migrations.');
  process.exit(1);
}

const store = new PostgresStore(url);
try {
  await store.init();
  console.log('Schéma appliqué avec succès.');
} finally {
  await store.close();
}
