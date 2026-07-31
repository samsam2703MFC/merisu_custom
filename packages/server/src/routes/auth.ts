/** Connexion et contexte utilisateur (§3.1.1 — connexion + choix du poste). */

import { Router } from 'express';

import { authenticate, requireAuth } from '../auth/middleware.js';
import { createToken } from '../auth/token.js';
import { asyncHandler } from '../http/asyncHandler.js';
import type { AppContext } from '../http/context.js';
import { badRequest, unauthorized } from '../http/errors.js';

export function authRoutes(ctx: AppContext): Router {
  const router = Router();

  /**
   * Connexion. Les identifiants sont vérifiés par le module Consultant existant
   * (via l'adaptateur), jamais par ce module.
   */
  router.post(
    '/login',
    asyncHandler(async (req, res) => {
      const { login, secret, workstationId } = req.body ?? {};
      if (typeof login !== 'string' || typeof secret !== 'string') {
        throw badRequest('MISSING_CREDENTIALS');
      }

      const consultant = await ctx.consultants.authenticate({ login, secret });
      if (!consultant) throw unauthorized('INVALID_CREDENTIALS');

      // Poste : celui choisi à la connexion, sinon le poste pré-affecté.
      const assigned = await ctx.consultants.getAssignedWorkstation(consultant.id);
      const selected = typeof workstationId === 'string' ? workstationId : null;
      const resolvedWorkstation = selected ?? assigned?.id ?? null;

      if (selected && !(await ctx.consultants.getWorkstation(selected))) {
        throw badRequest('UNKNOWN_WORKSTATION');
      }

      const token = createToken(
        { sub: consultant.id, role: consultant.role, workstationId: resolvedWorkstation },
        ctx.config.authSecret,
        ctx.config.tokenTtlSeconds,
      );

      res.json({ token, consultant, workstationId: resolvedWorkstation });
    }),
  );

  router.get(
    '/me',
    authenticate(ctx.config.authSecret),
    asyncHandler(async (req, res) => {
      const auth = requireAuth(req);
      const consultant = await ctx.consultants.getConsultant(auth.sub);
      if (!consultant) throw unauthorized('UNKNOWN_CONSULTANT');

      const settings = await ctx.store.getSettings();
      res.json({ consultant, workstationId: auth.workstationId, settings });
    }),
  );

  /** Changement de poste en cours de session : réémet un jeton. */
  router.post(
    '/workstation',
    authenticate(ctx.config.authSecret),
    asyncHandler(async (req, res) => {
      const auth = requireAuth(req);
      const { workstationId } = req.body ?? {};
      if (typeof workstationId !== 'string') throw badRequest('MISSING_WORKSTATION');
      if (!(await ctx.consultants.getWorkstation(workstationId))) {
        throw badRequest('UNKNOWN_WORKSTATION');
      }

      const token = createToken(
        { sub: auth.sub, role: auth.role, workstationId },
        ctx.config.authSecret,
        ctx.config.tokenTtlSeconds,
      );
      res.json({ token, workstationId });
    }),
  );

  return router;
}
