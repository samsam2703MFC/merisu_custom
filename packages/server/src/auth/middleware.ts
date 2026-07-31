/** Authentification et contrôle de rôle (§2 — rôles & permissions). */

import type { Role } from '@merisu/domain';
import type { NextFunction, Request, Response } from 'express';

import { forbidden, unauthorized } from '../http/errors.js';
import { verifyToken, type TokenPayload } from './token.js';

declare global {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace Express {
    interface Request {
      auth?: TokenPayload;
    }
  }
}

export function authenticate(secret: string) {
  return (req: Request, _res: Response, next: NextFunction): void => {
    const header = req.header('authorization');
    if (!header?.startsWith('Bearer ')) {
      next(unauthorized('MISSING_TOKEN'));
      return;
    }

    const payload = verifyToken(header.slice('Bearer '.length).trim(), secret);
    if (!payload) {
      next(unauthorized('INVALID_TOKEN'));
      return;
    }

    req.auth = payload;
    next();
  };
}

export function requireRole(...roles: Role[]) {
  return (req: Request, _res: Response, next: NextFunction): void => {
    if (!req.auth) {
      next(unauthorized());
      return;
    }
    if (!roles.includes(req.auth.role)) {
      next(forbidden('ROLE_NOT_ALLOWED'));
      return;
    }
    next();
  };
}

/** Récupère le contexte authentifié ou lève — évite les `!` partout dans les routes. */
export function requireAuth(req: Request): TokenPayload {
  if (!req.auth) throw unauthorized();
  return req.auth;
}
