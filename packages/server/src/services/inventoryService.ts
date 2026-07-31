/**
 * Service Inventaire — orchestration du parcours consultant (§3.1, §3.2).
 *
 * Il ne réimplémente AUCUNE formule : tous les calculs viennent de `@merisu/domain`,
 * ce qui garantit que la PWA (qui utilise le même paquet) affiche le même chiffre.
 */

import {
  canEditCount,
  computeDailyNet,
  computeProductionPlan,
  dayOfWeekOf,
  previousDay,
  validateEveningCount,
  type CountMoment,
  type DailyNet,
  type GeneralSettings,
  type InventoryCount,
  type ProductionPlan,
  type Product,
  type Role,
  type ValidationIssue,
} from '@merisu/domain';

import { badRequest, conflict, forbidden, notFound } from '../http/errors.js';
import type { Store } from '../store/types.js';

export interface Actor {
  id: string;
  role: Role;
}

export interface CountEntryInput {
  productId: string;
  qty: number;
  /** Production entrée en stock dans la journée (optionnelle, §5.1). */
  producedQty?: number | null;
}

/** Vue complète d'un écran de saisie (matin ou soir). */
export interface DaySheet {
  date: string;
  workstationId: string;
  moment: CountMoment;
  settings: GeneralSettings;
  products: Product[];
  counts: InventoryCount[];
  /** Le comptage de ce moment est-il déjà validé (donc verrouillé) ? */
  validated: boolean;
  validatedAt: string | null;
  validatedBy: string | null;
  /** Plan calculé la veille pour aujourd'hui — affiché au consultant le matin (§3.1.3). */
  planForToday: ProductionPlan[];
  /** Écart net du jour, dès que matin et soir sont saisis (§5.1). */
  dailyNets: DailyNet[];
}

export class InventoryService {
  constructor(private readonly store: Store) {}

  /** Assemble tout ce dont un écran de saisie a besoin, en une seule requête réseau. */
  async getDaySheet(params: {
    date: string;
    workstationId: string;
    moment: CountMoment;
  }): Promise<DaySheet> {
    const [settings, products, counts, planForToday] = await Promise.all([
      this.store.getSettings(),
      this.store.listProducts({ activeOnly: true }),
      this.store.findCounts({ date: params.date, workstationId: params.workstationId }),
      this.store.getProductionPlan({
        forDate: params.date,
        workstationId: params.workstationId,
      }),
    ]);

    const forMoment = counts.filter((c) => c.moment === params.moment);
    const validatedCount = forMoment.find((c) => c.validated);

    const opening = indexQty(counts, 'OPEN_0800');
    const closing = indexQty(counts, 'CLOSE_2200');
    const produced = indexProduced(counts);

    const dailyNets = products.map((product) =>
      computeDailyNet({
        date: params.date,
        productId: product.id,
        workstationId: params.workstationId,
        openingStock: opening[product.id] ?? null,
        closingStock: closing[product.id] ?? null,
        producedQty: produced[product.id] ?? 0,
      }),
    );

    return {
      date: params.date,
      workstationId: params.workstationId,
      moment: params.moment,
      settings,
      products,
      counts: forMoment,
      validated: Boolean(validatedCount),
      validatedAt: validatedCount?.validatedAt ?? null,
      validatedBy: validatedCount?.validatedBy ?? null,
      planForToday,
      dailyNets,
    };
  }

  /**
   * Enregistre (ou met à jour) les quantités comptées.
   *
   * Écriture en masse et idempotente : c'est ce qui permet à la file d'attente
   * hors-ligne de rejouer un envoi sans créer de doublon (§2 — PWA).
   */
  async saveCounts(params: {
    date: string;
    workstationId: string;
    moment: CountMoment;
    entries: CountEntryInput[];
    actor: Actor;
  }): Promise<InventoryCount[]> {
    if (params.entries.length === 0) throw badRequest('NO_ENTRIES');

    const activeProducts = await this.store.listProducts({ activeOnly: true });
    const activeIds = new Set(activeProducts.map((p) => p.id));

    const existing = await this.store.findCounts({
      date: params.date,
      workstationId: params.workstationId,
      moment: params.moment,
    });

    // Verrouillage après validation : seul un ADMIN peut encore corriger (§5.3).
    const locked = existing.some((c) => c.validated);
    if (locked && !canEditCount({ validated: true, role: params.actor.role })) {
      throw conflict('COUNT_LOCKED');
    }

    const saved: InventoryCount[] = [];
    for (const entry of params.entries) {
      if (!activeIds.has(entry.productId)) throw badRequest('UNKNOWN_OR_INACTIVE_PRODUCT');
      if (!Number.isFinite(entry.qty) || entry.qty < 0) throw badRequest('INVALID_QTY');

      saved.push(
        await this.store.upsertCount({
          date: params.date,
          workstationId: params.workstationId,
          consultantId: params.actor.id,
          productId: entry.productId,
          moment: params.moment,
          qty: entry.qty,
          producedQty: entry.producedQty ?? undefined,
        }),
      );
    }

    await this.audit({
      actor: params.actor,
      action: locked ? 'COUNT_EDITED_AFTER_VALIDATION' : 'COUNT_SAVED',
      workstationId: params.workstationId,
      businessDate: params.date,
      details: { moment: params.moment, products: params.entries.length },
    });

    return saved;
  }

  /** Joint une photo à un comptage existant (preuve du stock du soir). */
  async addPhoto(params: {
    countId: string;
    url: string;
    takenAt?: string;
    actor: Actor;
  }): Promise<InventoryCount> {
    const count = await this.store.getCount(params.countId);
    if (!count) throw notFound('COUNT_NOT_FOUND');

    if (!canEditCount({ validated: count.validated, role: params.actor.role })) {
      throw conflict('COUNT_LOCKED');
    }

    const updated = await this.store.addPhoto({
      inventoryCountId: params.countId,
      url: params.url,
      takenAt: params.takenAt ?? new Date().toISOString(),
    });

    await this.audit({
      actor: params.actor,
      action: 'PHOTO_ADDED',
      workstationId: count.workstationId,
      businessDate: count.date,
      details: { countId: count.id, productId: count.productId },
    });

    return updated;
  }

  /**
   * Joint une photo désignée par sa CLÉ MÉTIER (jour, poste, produit, moment).
   *
   * C'est la variante utilisée par la PWA : hors-ligne, l'identifiant technique du
   * comptage n'existe pas encore. La file rejoue les requêtes dans l'ordre, donc
   * l'enregistrement des quantités précède toujours l'envoi de la photo.
   */
  async addPhotoByKey(params: {
    date: string;
    workstationId: string;
    productId: string;
    moment: CountMoment;
    url: string;
    takenAt?: string;
    actor: Actor;
  }): Promise<InventoryCount> {
    const counts = await this.store.findCounts({
      date: params.date,
      workstationId: params.workstationId,
      productId: params.productId,
      moment: params.moment,
    });

    const count = counts[0];
    if (!count) throw notFound('COUNT_NOT_FOUND');

    return this.addPhoto({
      countId: count.id,
      url: params.url,
      takenAt: params.takenAt,
      actor: params.actor,
    });
  }

  /**
   * Valide un comptage.
   *
   * Pour le SOIR : vérifie les règles §5.3, verrouille les saisies, puis calcule
   * et FIGE le plan de production du lendemain (§5.2) — c'est le cœur du parcours.
   */
  async validate(params: {
    date: string;
    workstationId: string;
    moment: CountMoment;
    actor: Actor;
  }): Promise<{ counts: InventoryCount[]; plan: ProductionPlan[]; issues: ValidationIssue[] }> {
    const [settings, activeProducts, counts] = await Promise.all([
      this.store.getSettings(),
      this.store.listProducts({ activeOnly: true }),
      this.store.findCounts({
        date: params.date,
        workstationId: params.workstationId,
        moment: params.moment,
      }),
    ]);

    if (activeProducts.length === 0) throw badRequest('NO_ACTIVE_PRODUCT');

    const quantities: Record<string, number | null> = {};
    const photoCountByProduct: Record<string, number> = {};
    for (const count of counts) {
      quantities[count.productId] = count.qty;
      photoCountByProduct[count.productId] = count.photos.length;
    }

    // La photo n'est exigée qu'au comptage du soir : le matin, le consultant compte
    // à l'ouverture, la preuve photo porte sur la clôture (§3.2.2).
    const photoSettings =
      params.moment === 'CLOSE_2200'
        ? { photoRequired: settings.photoRequired, photoPerProduct: settings.photoPerProduct }
        : { photoRequired: false, photoPerProduct: false };

    const validation = validateEveningCount({
      activeProducts,
      quantities,
      photoCountByProduct,
      globalPhotoCount: 0,
      settings: photoSettings,
      alreadyValidated: counts.some((c) => c.validated),
    });

    if (!validation.valid) {
      throw conflict('VALIDATION_FAILED', validation.issues);
    }

    const validatedCounts = await this.store.setCountsValidated({
      date: params.date,
      workstationId: params.workstationId,
      moment: params.moment,
      validated: true,
      validatedBy: params.actor.id,
    });

    await this.audit({
      actor: params.actor,
      action: params.moment === 'CLOSE_2200' ? 'EVENING_VALIDATED' : 'MORNING_VALIDATED',
      workstationId: params.workstationId,
      businessDate: params.date,
      details: { products: validatedCounts.length },
    });

    const plan =
      params.moment === 'CLOSE_2200'
        ? await this.computeAndFreezePlan({
            date: params.date,
            workstationId: params.workstationId,
            actor: params.actor,
          })
        : [];

    return { counts: validatedCounts, plan, issues: [] };
  }

  /** Déverrouillage d'un comptage validé — ADMIN uniquement, tracé (§5.3). */
  async unlock(params: {
    date: string;
    workstationId: string;
    moment: CountMoment;
    reason?: string;
    actor: Actor;
  }): Promise<InventoryCount[]> {
    if (params.actor.role !== 'ADMIN') throw forbidden('ADMIN_ONLY');

    const counts = await this.store.setCountsValidated({
      date: params.date,
      workstationId: params.workstationId,
      moment: params.moment,
      validated: false,
      validatedBy: params.actor.id,
    });

    if (counts.length === 0) throw notFound('COUNT_NOT_FOUND');

    await this.audit({
      actor: params.actor,
      action: 'COUNT_UNLOCKED',
      workstationId: params.workstationId,
      businessDate: params.date,
      details: { moment: params.moment, reason: params.reason ?? null },
    });

    return counts;
  }

  /**
   * Calcule le plan du lendemain à partir des stocks de clôture du jour et le fige.
   * Appelé automatiquement à la validation du soir.
   */
  async computeAndFreezePlan(params: {
    date: string;
    workstationId: string;
    actor: Actor;
  }): Promise<ProductionPlan[]> {
    const computed = await this.previewPlan({
      date: params.date,
      workstationId: params.workstationId,
    });
    const computedAt = new Date().toISOString();

    const plan = await this.store.replaceProductionPlan({
      forDate: computed.forDate,
      workstationId: params.workstationId,
      lines: computed.lines.map((line) => ({
        forDate: computed.forDate,
        productId: line.productId,
        workstationId: params.workstationId,
        requiredPieces: line.requiredPieces,
        closingStock: line.closingStock,
        qtyToProduce: line.qtyToProduce,
        computedAt,
        status: 'FROZEN' as const,
        missingThreshold: line.missingThreshold,
      })),
    });

    await this.audit({
      actor: params.actor,
      action: 'PRODUCTION_PLAN_FROZEN',
      workstationId: params.workstationId,
      businessDate: params.date,
      details: {
        forDate: computed.forDate,
        lines: plan.length,
        missingThresholds: computed.warningsMissingThreshold,
      },
    });

    return plan;
  }

  /**
   * Calcule le plan SANS l'enregistrer — utilisé pour l'aperçu avant validation
   * et pour recalculer un plan absent (poste ouvert sans clôture la veille).
   */
  async previewPlan(params: { date: string; workstationId: string }) {
    const [products, parMatrix, closingCounts] = await Promise.all([
      this.store.listProducts({ activeOnly: true }),
      this.store.listParMatrix(),
      this.store.findCounts({
        date: params.date,
        workstationId: params.workstationId,
        moment: 'CLOSE_2200',
      }),
    ]);

    const closingStocks: Record<string, number | null> = {};
    for (const product of products) closingStocks[product.id] = null;
    for (const count of closingCounts) closingStocks[count.productId] = count.qty;

    return computeProductionPlan({
      today: params.date,
      products,
      closingStocks,
      parMatrix,
      workstationId: params.workstationId,
    });
  }

  /** Plan figé pour une date donnée ; vide si le soir précédent n'a pas été validé. */
  async getPlan(params: { forDate: string; workstationId: string }): Promise<{
    forDate: string;
    dayOfWeek: string;
    /** Date du comptage de clôture qui a produit ce plan. */
    computedFromDate: string;
    lines: ProductionPlan[];
    frozen: boolean;
  }> {
    const lines = await this.store.getProductionPlan(params);
    return {
      forDate: params.forDate,
      dayOfWeek: dayOfWeekOf(params.forDate),
      computedFromDate: previousDay(params.forDate),
      lines,
      frozen: lines.length > 0,
    };
  }

  private async audit(params: {
    actor: Actor;
    action: string;
    workstationId: string | null;
    businessDate: string | null;
    details: Record<string, unknown>;
  }): Promise<void> {
    await this.store.appendAudit({
      at: new Date().toISOString(),
      actorId: params.actor.id,
      actorRole: params.actor.role,
      action: params.action,
      workstationId: params.workstationId,
      businessDate: params.businessDate,
      details: params.details,
    });
  }
}

function indexQty(counts: InventoryCount[], moment: CountMoment): Record<string, number> {
  const out: Record<string, number> = {};
  for (const count of counts) {
    if (count.moment === moment) out[count.productId] = count.qty;
  }
  return out;
}

function indexProduced(counts: InventoryCount[]): Record<string, number> {
  const out: Record<string, number> = {};
  for (const count of counts) {
    if (count.producedQty !== null && count.producedQty !== undefined) {
      out[count.productId] = (out[count.productId] ?? 0) + count.producedQty;
    }
  }
  return out;
}

