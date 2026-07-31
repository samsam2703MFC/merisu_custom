/**
 * Configuration serveur — tout est pilotable par variables d'environnement.
 *
 * Les PARAMÈTRES MÉTIER (horaires, langues, seuils, tolérance…) ne sont pas ici :
 * ils vivent en base et se modifient depuis l'admin, sans redéploiement (§2).
 */

export type StoreKind = 'memory' | 'postgres';

export interface ServerConfig {
  port: number;
  /** `memory` : démarrage sans base (démo, tests). `postgres` : production. */
  store: StoreKind;
  databaseUrl: string | undefined;
  /** Fichier de persistance du store mémoire ; vide = purement volatil. */
  memorySnapshotFile: string | undefined;
  /** Secret de signature des jetons. À DÉFINIR en production. */
  authSecret: string;
  /** Durée de vie du jeton, en secondes. */
  tokenTtlSeconds: number;
  /** Origines autorisées pour le CORS (`*` par défaut en développement). */
  corsOrigins: string[];
  /** Taille maximale d'un envoi (photos en base64). */
  jsonBodyLimit: string;
  /** Dossier de stockage des photos de comptage. */
  uploadDir: string;
  /** URL publique servant les photos. */
  uploadPublicPath: string;
}

function envInt(name: string, fallback: number): number {
  const raw = process.env[name];
  if (!raw) return fallback;
  const parsed = Number.parseInt(raw, 10);
  return Number.isFinite(parsed) ? parsed : fallback;
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): ServerConfig {
  const store = (env.STORE ?? 'memory') as StoreKind;

  if (store !== 'memory' && store !== 'postgres') {
    throw new Error(`STORE invalide : "${store}" (attendu "memory" ou "postgres")`);
  }
  if (store === 'postgres' && !env.DATABASE_URL) {
    throw new Error('STORE=postgres requiert DATABASE_URL');
  }

  const authSecret = env.AUTH_SECRET ?? 'merisu-dev-secret-a-remplacer';
  if (env.NODE_ENV === 'production' && authSecret === 'merisu-dev-secret-a-remplacer') {
    throw new Error('AUTH_SECRET doit être défini en production');
  }

  return {
    port: envInt('PORT', 3001),
    store,
    databaseUrl: env.DATABASE_URL,
    memorySnapshotFile: env.MEMORY_SNAPSHOT_FILE,
    authSecret,
    tokenTtlSeconds: envInt('TOKEN_TTL_SECONDS', 60 * 60 * 12),
    corsOrigins: (env.CORS_ORIGINS ?? '*').split(',').map((s) => s.trim()).filter(Boolean),
    jsonBodyLimit: env.JSON_BODY_LIMIT ?? '12mb',
    uploadDir: env.UPLOAD_DIR ?? 'uploads',
    uploadPublicPath: env.UPLOAD_PUBLIC_PATH ?? '/uploads',
  };
}
