/**
 * File d'attente hors-ligne (§2).
 *
 * Toute écriture du consultant (quantités, photos, validation) passe par ici :
 *   • en ligne  → envoi immédiat ;
 *   • hors ligne → mise en file persistante, rejouée dans l'ordre à la reconnexion.
 *
 * L'API d'écriture des comptages est idempotente (clé date+poste+produit+moment),
 * donc rejouer un envoi déjà passé ne crée jamais de doublon.
 */

import { QUEUE_STORE, idb } from './db.js';

export interface QueuedRequest {
  id?: number;
  method: 'PUT' | 'POST';
  path: string;
  body: unknown;
  createdAt: string;
  /** Nombre d'échecs consécutifs, pour ne pas boucler sur une requête morte. */
  attempts: number;
  /** Dernier code d'erreur rencontré, affiché à l'utilisateur si abandon. */
  lastError?: string;
}

/** Au-delà, la requête est considérée comme définitivement en échec. */
const MAX_ATTEMPTS = 5;

type Listener = (state: QueueState) => void;

export interface QueueState {
  pending: number;
  syncing: boolean;
  online: boolean;
  /** Requêtes abandonnées après trop d'échecs — à signaler à l'utilisateur. */
  failed: QueuedRequest[];
}

let listeners: Listener[] = [];
let syncing = false;
let failed: QueuedRequest[] = [];

async function notify(): Promise<void> {
  const pending = (await idb.getAll<QueuedRequest>(QUEUE_STORE)).length;
  const state: QueueState = { pending, syncing, online: navigator.onLine, failed: [...failed] };
  for (const listener of listeners) listener(state);
}

export function subscribeQueue(listener: Listener): () => void {
  listeners.push(listener);
  void notify();
  return () => {
    listeners = listeners.filter((l) => l !== listener);
  };
}

export async function enqueue(request: Omit<QueuedRequest, 'createdAt' | 'attempts'>): Promise<void> {
  await idb.add<QueuedRequest>(QUEUE_STORE, {
    ...request,
    createdAt: new Date().toISOString(),
    attempts: 0,
  });
  await notify();
}

export async function queueLength(): Promise<number> {
  return (await idb.getAll<QueuedRequest>(QUEUE_STORE)).length;
}

export function clearFailed(): void {
  failed = [];
  void notify();
}

/**
 * Rejoue la file dans l'ordre d'arrivée.
 *
 * L'ordre compte : une validation ne doit jamais partir avant les quantités
 * qu'elle valide. On s'arrête donc au premier échec réseau au lieu de continuer.
 */
export async function flushQueue(
  send: (request: QueuedRequest) => Promise<void>,
): Promise<{ sent: number; remaining: number }> {
  if (syncing || !navigator.onLine) {
    return { sent: 0, remaining: await queueLength() };
  }

  syncing = true;
  await notify();
  let sent = 0;

  try {
    const queued = (await idb.getAll<QueuedRequest>(QUEUE_STORE)).sort(
      (a, b) => (a.id ?? 0) - (b.id ?? 0),
    );

    for (const request of queued) {
      try {
        await send(request);
        if (request.id !== undefined) await idb.delete(QUEUE_STORE, request.id);
        sent += 1;
      } catch (error) {
        const attempts = request.attempts + 1;
        const code = error instanceof Error ? error.message : 'UNKNOWN';

        // Erreur métier (4xx) : rejouer n'y changera rien, on abandonne tout de suite.
        const permanent = code.startsWith('HTTP_4') || attempts >= MAX_ATTEMPTS;

        if (permanent) {
          if (request.id !== undefined) await idb.delete(QUEUE_STORE, request.id);
          failed.push({ ...request, attempts, lastError: code });
          continue;
        }

        if (request.id !== undefined) {
          await idb.put<QueuedRequest>(QUEUE_STORE, { ...request, attempts, lastError: code });
        }
        // Panne réseau : on préserve l'ordre en stoppant ici.
        break;
      }
    }
  } finally {
    syncing = false;
    await notify();
  }

  return { sent, remaining: await queueLength() };
}

/** Branche le rejeu automatique sur les évènements réseau du navigateur. */
export function watchConnectivity(onOnline: () => void): () => void {
  const handleOnline = (): void => {
    void notify();
    onOnline();
  };
  const handleOffline = (): void => {
    void notify();
  };

  window.addEventListener('online', handleOnline);
  window.addEventListener('offline', handleOffline);

  return () => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
  };
}
