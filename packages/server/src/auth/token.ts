/**
 * Jetons de session signés (HMAC-SHA256), sans dépendance externe.
 *
 * Format volontairement proche d'un JWT : `payloadBase64Url.signatureBase64Url`.
 * TODO INTÉGRATION : si le module Consultant existant fournit déjà des jetons,
 * remplacer la vérification locale par la sienne (cf. `consultantService.ts`).
 */

import { createHmac, timingSafeEqual } from 'node:crypto';

import type { Role } from '@merisu/domain';

export interface TokenPayload {
  /** Identifiant du consultant. */
  sub: string;
  role: Role;
  /** Poste sélectionné à la connexion. */
  workstationId: string | null;
  /** Expiration, en secondes epoch. */
  exp: number;
}

function base64url(input: Buffer | string): string {
  return Buffer.from(input)
    .toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
}

function fromBase64url(input: string): Buffer {
  const padded = input.replace(/-/g, '+').replace(/_/g, '/');
  return Buffer.from(padded + '='.repeat((4 - (padded.length % 4)) % 4), 'base64');
}

function sign(payload: string, secret: string): string {
  return base64url(createHmac('sha256', secret).update(payload).digest());
}

export function createToken(
  payload: Omit<TokenPayload, 'exp'>,
  secret: string,
  ttlSeconds: number,
): string {
  const full: TokenPayload = { ...payload, exp: Math.floor(Date.now() / 1000) + ttlSeconds };
  const encoded = base64url(JSON.stringify(full));
  return `${encoded}.${sign(encoded, secret)}`;
}

/** Renvoie le contenu du jeton, ou null si signature invalide / jeton expiré. */
export function verifyToken(token: string, secret: string): TokenPayload | null {
  const [encoded, signature] = token.split('.');
  if (!encoded || !signature) return null;

  const expected = sign(encoded, secret);
  const a = Buffer.from(signature);
  const b = Buffer.from(expected);
  // Comparaison à temps constant : évite les attaques par mesure de temps.
  if (a.length !== b.length || !timingSafeEqual(a, b)) return null;

  try {
    const payload = JSON.parse(fromBase64url(encoded).toString('utf8')) as TokenPayload;
    if (typeof payload.exp !== 'number' || payload.exp < Math.floor(Date.now() / 1000)) {
      return null;
    }
    return payload;
  } catch {
    return null;
  }
}
