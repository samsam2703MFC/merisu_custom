/** Écrans d'administration (§3.3, §7.5 à §7.9). Réservé au rôle ADMIN. */

import {
  DAYS_OF_WEEK,
  SUPPORTED_LOCALES,
  isIsoDate,
  type DayOfWeek,
  type Locale,
  type Product,
  type RoundingMode,
} from '@merisu/domain';
import { Router } from 'express';

import { authenticate, requireAuth, requireRole } from '../auth/middleware.js';
import { asyncHandler } from '../http/asyncHandler.js';
import type { AppContext } from '../http/context.js';
import { badRequest, notFound } from '../http/errors.js';

const ROUNDING_MODES: RoundingMode[] = ['CEIL', 'FLOOR', 'NEAREST', 'NONE'];

export function adminRoutes(ctx: AppContext): Router {
  const router = Router();
  router.use(authenticate(ctx.config.authSecret));

  // ── Produits (lecture ouverte aux consultants, écriture ADMIN) ─────────────

  router.get(
    '/products',
    asyncHandler(async (req, res) => {
      const auth = requireAuth(req);
      // Le consultant ne voit que les produits actifs ; l'admin voit les 8 slots.
      const activeOnly = auth.role !== 'ADMIN' || req.query.activeOnly === 'true';
      res.json({ products: await ctx.store.listProducts({ activeOnly }) });
    }),
  );

  /** Paramètres produit : libellés multilingues, unité, actif, perte, arrondi (§7.6). */
  router.put(
    '/products/:id',
    requireRole('ADMIN'),
    asyncHandler(async (req, res) => {
      const existing = await ctx.store.getProduct(req.params.id!);
      if (!existing) throw notFound('PRODUCT_NOT_FOUND');

      const body = req.body ?? {};
      const next: Product = {
        ...existing,
        name: body.name === undefined ? existing.name : sanitizeI18n(body.name),
        unit: body.unit === undefined ? existing.unit : String(body.unit).slice(0, 16),
        active: body.active === undefined ? existing.active : Boolean(body.active),
        wasteFactor:
          body.wasteFactor === undefined
            ? existing.wasteFactor
            : requireNonNegative(body.wasteFactor, 'INVALID_WASTE_FACTOR'),
        roundingStep:
          body.roundingStep === undefined
            ? existing.roundingStep
            : requirePositive(body.roundingStep, 'INVALID_ROUNDING_STEP'),
        roundingMode:
          body.roundingMode === undefined
            ? existing.roundingMode
            : requireRoundingMode(body.roundingMode),
        recipeRef: body.recipeRef === undefined ? existing.recipeRef : (body.recipeRef ?? null),
        sortOrder:
          body.sortOrder === undefined ? existing.sortOrder : Number(body.sortOrder) || 0,
      };

      const product = await ctx.store.upsertProduct(next);
      await audit(ctx, req, 'PRODUCT_UPDATED', { productId: product.id });

      res.json({ product });
    }),
  );

  // ── Matrice des seuils (§7.5) ─────────────────────────────────────────────

  router.get(
    '/par-matrix',
    asyncHandler(async (_req, res) => {
      res.json({ entries: await ctx.store.listParMatrix(), days: DAYS_OF_WEEK });
    }),
  );

  /**
   * Édition de la matrice jour × produit.
   * `requiredPieces: null` supprime le seuil (≠ seuil à 0, qui est un choix explicite).
   */
  router.put(
    '/par-matrix',
    requireRole('ADMIN'),
    asyncHandler(async (req, res) => {
      const entries = req.body?.entries;
      if (!Array.isArray(entries)) throw badRequest('INVALID_ENTRIES');

      const products = await ctx.store.listProducts();
      const productIds = new Set(products.map((p) => p.id));

      const normalized = entries.map((entry: any) => {
        if (!productIds.has(String(entry?.productId))) throw badRequest('UNKNOWN_PRODUCT');
        if (!DAYS_OF_WEEK.includes(entry?.dayOfWeek)) throw badRequest('INVALID_DAY_OF_WEEK');

        const raw = entry?.requiredPieces;
        const requiredPieces =
          raw === null || raw === undefined || raw === ''
            ? null
            : requireNonNegative(raw, 'INVALID_REQUIRED_PIECES');

        return {
          productId: String(entry.productId),
          dayOfWeek: entry.dayOfWeek as DayOfWeek,
          requiredPieces,
          workstationId: entry?.workstationId ? String(entry.workstationId) : null,
        };
      });

      const updated = await ctx.store.upsertParEntries(normalized);
      await audit(ctx, req, 'PAR_MATRIX_UPDATED', { entries: normalized.length });

      res.json({ entries: updated });
    }),
  );

  // ── Paramètres généraux (§7.7) ────────────────────────────────────────────

  router.get(
    '/settings',
    asyncHandler(async (_req, res) => {
      res.json({ settings: await ctx.store.getSettings(), locales: SUPPORTED_LOCALES });
    }),
  );

  router.put(
    '/settings',
    requireRole('ADMIN'),
    asyncHandler(async (req, res) => {
      const body = req.body ?? {};
      const patch: Record<string, unknown> = {};

      if (body.openingTime !== undefined) patch.openingTime = requireTime(body.openingTime);
      if (body.closingTime !== undefined) patch.closingTime = requireTime(body.closingTime);
      if (body.timezone !== undefined) patch.timezone = requireTimezone(body.timezone);
      if (body.defaultLocale !== undefined) patch.defaultLocale = requireLocale(body.defaultLocale);
      if (body.photoRequired !== undefined) patch.photoRequired = Boolean(body.photoRequired);
      if (body.photoPerProduct !== undefined) patch.photoPerProduct = Boolean(body.photoPerProduct);
      if (body.deltaTolerance !== undefined) {
        patch.deltaTolerance = requireNonNegative(body.deltaTolerance, 'INVALID_TOLERANCE');
      }

      const settings = await ctx.store.updateSettings(patch);
      await audit(ctx, req, 'SETTINGS_UPDATED', { fields: Object.keys(patch) });

      res.json({ settings });
    }),
  );

  // ── Recettes & matières (lecture seule, module existant) ──────────────────

  router.get(
    '/materials',
    asyncHandler(async (_req, res) => {
      res.json({ materials: await ctx.recipes.listMaterials() });
    }),
  );

  router.get(
    '/recipes',
    asyncHandler(async (_req, res) => {
      const products = await ctx.store.listProducts();
      res.json({ recipes: await ctx.recipes.getRecipes(products.map((p) => p.id)) });
    }),
  );

  // ── Consommation réelle de matières (base du delta technique) ─────────────

  router.get(
    '/material-movements',
    requireRole('ADMIN'),
    asyncHandler(async (req, res) => {
      res.json({
        movements: await ctx.store.listMaterialMovements({
          from: optionalDate(req.query.from),
          to: optionalDate(req.query.to),
          workstationId: optionalString(req.query.workstationId),
        }),
      });
    }),
  );

  router.post(
    '/material-movements',
    requireRole('ADMIN'),
    asyncHandler(async (req, res) => {
      const auth = requireAuth(req);
      const body = req.body ?? {};

      if (typeof body.date !== 'string' || !isIsoDate(body.date)) throw badRequest('INVALID_DATE');
      if (typeof body.materialId !== 'string' || !body.materialId) {
        throw badRequest('MISSING_MATERIAL');
      }
      if (!Number.isFinite(Number(body.realQty))) throw badRequest('INVALID_QTY');

      const movement = await ctx.store.addMaterialMovement({
        date: body.date,
        materialId: body.materialId,
        realQty: Number(body.realQty),
        workstationId: body.workstationId ? String(body.workstationId) : null,
        recordedBy: auth.sub,
        recordedAt: new Date().toISOString(),
        note: body.note ? String(body.note) : null,
      });

      res.status(201).json({ movement });
    }),
  );

  // ── Historique / audit (§7.9) ─────────────────────────────────────────────

  router.get(
    '/audit',
    requireRole('ADMIN'),
    asyncHandler(async (req, res) => {
      res.json({
        entries: await ctx.store.listAudit({
          from: optionalDate(req.query.from),
          to: optionalDate(req.query.to),
          workstationId: optionalString(req.query.workstationId),
          actorId: optionalString(req.query.actorId),
          limit: req.query.limit ? Number(req.query.limit) : undefined,
        }),
      });
    }),
  );

  router.get(
    '/consultants',
    requireRole('ADMIN'),
    asyncHandler(async (_req, res) => {
      res.json({ consultants: await ctx.consultants.listConsultants() });
    }),
  );

  return router;
}

// ── Validation des entrées ──────────────────────────────────────────────────

function sanitizeI18n(value: unknown): Record<string, string> {
  if (!value || typeof value !== 'object') throw badRequest('INVALID_NAME');
  const out: Record<string, string> = {};
  for (const locale of SUPPORTED_LOCALES) {
    const raw = (value as Record<string, unknown>)[locale];
    if (typeof raw === 'string' && raw.trim() !== '') out[locale] = raw.trim().slice(0, 120);
  }
  return out;
}

function requireNonNegative(value: unknown, code: string): number {
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed < 0) throw badRequest(code);
  return parsed;
}

function requirePositive(value: unknown, code: string): number {
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed <= 0) throw badRequest(code);
  return parsed;
}

function requireRoundingMode(value: unknown): RoundingMode {
  if (!ROUNDING_MODES.includes(value as RoundingMode)) throw badRequest('INVALID_ROUNDING_MODE');
  return value as RoundingMode;
}

function requireTime(value: unknown): string {
  if (typeof value !== 'string' || !/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) {
    throw badRequest('INVALID_TIME');
  }
  return value;
}

function requireLocale(value: unknown): Locale {
  if (!SUPPORTED_LOCALES.includes(value as Locale)) throw badRequest('INVALID_LOCALE');
  return value as Locale;
}

function requireTimezone(value: unknown): string {
  if (typeof value !== 'string' || value.trim() === '') throw badRequest('INVALID_TIMEZONE');
  try {
    // Rejette un fuseau inconnu ici plutôt que de fausser silencieusement les dates métier.
    new Intl.DateTimeFormat('en-CA', { timeZone: value });
  } catch {
    throw badRequest('INVALID_TIMEZONE');
  }
  return value;
}

function optionalDate(value: unknown): string | undefined {
  if (typeof value !== 'string' || value === '') return undefined;
  if (!isIsoDate(value)) throw badRequest('INVALID_DATE');
  return value;
}

function optionalString(value: unknown): string | undefined {
  return typeof value === 'string' && value !== '' ? value : undefined;
}

async function audit(
  ctx: AppContext,
  req: Parameters<typeof requireAuth>[0],
  action: string,
  details: Record<string, unknown>,
): Promise<void> {
  const auth = requireAuth(req);
  await ctx.store.appendAudit({
    at: new Date().toISOString(),
    actorId: auth.sub,
    actorRole: auth.role,
    action,
    workstationId: auth.workstationId,
    businessDate: null,
    details,
  });
}
