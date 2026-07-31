/** Comptages, validation, photos et plan de production (§3.1, §3.2). */

import { randomUUID } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { businessToday, isIsoDate, type CountMoment } from '@merisu/domain';
import { Router } from 'express';

import { authenticate, requireAuth, requireRole } from '../auth/middleware.js';
import { asyncHandler } from '../http/asyncHandler.js';
import type { AppContext } from '../http/context.js';
import { AppError, badRequest, forbidden } from '../http/errors.js';

const MOMENTS: CountMoment[] = ['OPEN_0800', 'CLOSE_2200'];

/** Formats d'image acceptés pour les photos de comptage. */
const ALLOWED_IMAGE_TYPES: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
};

export function inventoryRoutes(ctx: AppContext): Router {
  const router = Router();
  router.use(authenticate(ctx.config.authSecret));

  /**
   * Poste effectif de la requête.
   * Un consultant est cantonné à son poste ; un admin peut viser n'importe lequel.
   */
  function resolveWorkstation(req: Parameters<typeof requireAuth>[0], requested?: unknown): string {
    const auth = requireAuth(req);
    const asked = typeof requested === 'string' && requested ? requested : null;

    if (auth.role === 'ADMIN') {
      const target = asked ?? auth.workstationId;
      if (!target) throw badRequest('MISSING_WORKSTATION');
      return target;
    }

    if (!auth.workstationId) throw badRequest('NO_WORKSTATION_ASSIGNED');
    if (asked && asked !== auth.workstationId) throw forbidden('WORKSTATION_NOT_ALLOWED');
    return auth.workstationId;
  }

  function parseMoment(value: unknown): CountMoment {
    if (typeof value !== 'string' || !MOMENTS.includes(value as CountMoment)) {
      throw badRequest('INVALID_MOMENT');
    }
    return value as CountMoment;
  }

  async function parseDate(value: unknown): Promise<string> {
    if (value === undefined || value === null || value === '') {
      const settings = await ctx.store.getSettings();
      return businessToday(settings.timezone);
    }
    if (typeof value !== 'string' || !isIsoDate(value)) throw badRequest('INVALID_DATE');
    return value;
  }

  /** Feuille de saisie complète : produits, quantités déjà saisies, plan du jour, écarts. */
  router.get(
    '/day-sheet',
    asyncHandler(async (req, res) => {
      const date = await parseDate(req.query.date);
      const workstationId = resolveWorkstation(req, req.query.workstationId);
      const moment = parseMoment(req.query.moment ?? 'OPEN_0800');

      res.json(await ctx.inventory.getDaySheet({ date, workstationId, moment }));
    }),
  );

  /**
   * Enregistrement en masse des quantités comptées.
   * Idempotent : rejouer le même envoi (file hors-ligne) ne crée pas de doublon.
   */
  router.put(
    '/counts',
    asyncHandler(async (req, res) => {
      const auth = requireAuth(req);
      const date = await parseDate(req.body?.date);
      const workstationId = resolveWorkstation(req, req.body?.workstationId);
      const moment = parseMoment(req.body?.moment);
      const entries = req.body?.entries;

      if (!Array.isArray(entries)) throw badRequest('INVALID_ENTRIES');

      const counts = await ctx.inventory.saveCounts({
        date,
        workstationId,
        moment,
        entries: entries.map((entry: any) => ({
          productId: String(entry?.productId ?? ''),
          qty: Number(entry?.qty),
          producedQty:
            entry?.producedQty === undefined || entry?.producedQty === null
              ? null
              : Number(entry.producedQty),
        })),
        actor: { id: auth.sub, role: auth.role },
      });

      res.json({ counts });
    }),
  );

  /**
   * Ajout d'une photo (preuve du stock du soir).
   * L'image arrive en base64 : c'est ce qui permet à la PWA de mettre la photo en
   * file d'attente hors-ligne et de l'envoyer à la reconnexion.
   */
  router.post(
    '/counts/:countId/photos',
    asyncHandler(async (req, res) => {
      const auth = requireAuth(req);
      const { dataBase64, contentType, takenAt } = req.body ?? {};

      if (typeof dataBase64 !== 'string' || dataBase64.length === 0) {
        throw badRequest('MISSING_PHOTO_DATA');
      }
      const extension = ALLOWED_IMAGE_TYPES[String(contentType)];
      if (!extension) throw badRequest('UNSUPPORTED_IMAGE_TYPE');

      const buffer = Buffer.from(dataBase64.replace(/^data:[^;]+;base64,/, ''), 'base64');
      if (buffer.length === 0) throw badRequest('INVALID_PHOTO_DATA');

      const fileName = `${randomUUID()}.${extension}`;
      mkdirSync(ctx.config.uploadDir, { recursive: true });
      writeFileSync(join(ctx.config.uploadDir, fileName), buffer);

      const count = await ctx.inventory.addPhoto({
        countId: req.params.countId!,
        url: `${ctx.config.uploadPublicPath}/${fileName}`,
        takenAt: typeof takenAt === 'string' ? takenAt : undefined,
        actor: { id: auth.sub, role: auth.role },
      });

      res.status(201).json({ count });
    }),
  );

  /**
   * Validation du comptage. Pour le soir : verrouille et calcule le plan du lendemain.
   * Les règles non satisfaites reviennent en 409 avec le détail par produit, pour
   * que la PWA surligne exactement les champs manquants.
   */
  router.post(
    '/validate',
    asyncHandler(async (req, res) => {
      const auth = requireAuth(req);
      const date = await parseDate(req.body?.date);
      const workstationId = resolveWorkstation(req, req.body?.workstationId);
      const moment = parseMoment(req.body?.moment);

      const result = await ctx.inventory.validate({
        date,
        workstationId,
        moment,
        actor: { id: auth.sub, role: auth.role },
      });

      res.json(result);
    }),
  );

  /** Déverrouillage d'une saisie validée — ADMIN uniquement, tracé. */
  router.post(
    '/unlock',
    requireRole('ADMIN'),
    asyncHandler(async (req, res) => {
      const auth = requireAuth(req);
      const date = await parseDate(req.body?.date);
      const workstationId = resolveWorkstation(req, req.body?.workstationId);
      const moment = parseMoment(req.body?.moment);

      const counts = await ctx.inventory.unlock({
        date,
        workstationId,
        moment,
        reason: typeof req.body?.reason === 'string' ? req.body.reason : undefined,
        actor: { id: auth.sub, role: auth.role },
      });

      res.json({ counts });
    }),
  );

  /** Plan figé pour une date (par défaut aujourd'hui) — écran « À produire ». */
  router.get(
    '/production-plan',
    asyncHandler(async (req, res) => {
      const forDate = await parseDate(req.query.forDate);
      const workstationId = resolveWorkstation(req, req.query.workstationId);

      res.json(await ctx.inventory.getPlan({ forDate, workstationId }));
    }),
  );

  /** Aperçu non figé du plan du lendemain (indicatif, avant validation du soir). */
  router.get(
    '/production-plan/preview',
    asyncHandler(async (req, res) => {
      const date = await parseDate(req.query.date);
      const workstationId = resolveWorkstation(req, req.query.workstationId);

      res.json(await ctx.inventory.previewPlan({ date, workstationId }));
    }),
  );

  /** Postes disponibles, fournis par le module Consultant existant. */
  router.get(
    '/workstations',
    asyncHandler(async (_req, res) => {
      res.json({ workstations: await ctx.consultants.listWorkstations() });
    }),
  );

  return router;
}

export { AppError };
