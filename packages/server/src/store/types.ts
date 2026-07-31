/**
 * Contrat de persistance du module.
 *
 * Toute la logique métier ne connaît que cette interface : elle est implémentée
 * par `MemoryStore` (démo/tests, sans base) et par `PostgresStore` (production).
 * Aucun SQL ne remonte au-dessus de cette couche.
 */

import type {
  CountMoment,
  GeneralSettings,
  InventoryCount,
  ParMatrixEntry,
  Product,
  ProductionPlan,
} from '@merisu/domain';

/** Consommation réelle de matière constatée sur une période (§6). */
export interface MaterialMovement {
  id: string;
  /** Date métier `YYYY-MM-DD`. */
  date: string;
  materialId: string;
  /** Quantité réellement consommée (variation de stock). */
  realQty: number;
  workstationId: string | null;
  recordedBy: string;
  recordedAt: string;
  note: string | null;
}

/** Entrée du journal d'audit (§2 — traçabilité). */
export interface AuditEntry {
  id: string;
  at: string;
  actorId: string;
  actorRole: string;
  /** Ex. `COUNT_SAVED`, `EVENING_VALIDATED`, `COUNT_UNLOCKED`, `SETTINGS_UPDATED`. */
  action: string;
  workstationId: string | null;
  /** Date métier concernée, si applicable. */
  businessDate: string | null;
  /** Détail libre, sérialisé en JSON. */
  details: Record<string, unknown>;
}

export interface CountQuery {
  date?: string;
  from?: string;
  to?: string;
  workstationId?: string;
  moment?: CountMoment;
  productId?: string;
}

export interface AuditQuery {
  from?: string;
  to?: string;
  workstationId?: string;
  actorId?: string;
  limit?: number;
}

/** Données d'un comptage à créer ou mettre à jour. */
export interface CountUpsert {
  date: string;
  workstationId: string;
  consultantId: string;
  productId: string;
  moment: CountMoment;
  qty: number;
  producedQty?: number | null;
}

export interface Store {
  /** Applique le schéma / prépare le stockage. Idempotent. */
  init(): Promise<void>;
  close(): Promise<void>;

  getSettings(): Promise<GeneralSettings>;
  updateSettings(patch: Partial<GeneralSettings>): Promise<GeneralSettings>;

  listProducts(options?: { activeOnly?: boolean }): Promise<Product[]>;
  getProduct(id: string): Promise<Product | null>;
  upsertProduct(product: Product): Promise<Product>;

  listParMatrix(): Promise<ParMatrixEntry[]>;
  /** Remplace/insère une série de seuils. `requiredPieces` null ⇒ suppression du seuil. */
  upsertParEntries(
    entries: Array<{
      productId: string;
      dayOfWeek: ParMatrixEntry['dayOfWeek'];
      requiredPieces: number | null;
      workstationId: string | null;
    }>,
  ): Promise<ParMatrixEntry[]>;

  findCounts(query: CountQuery): Promise<InventoryCount[]>;
  getCount(id: string): Promise<InventoryCount | null>;
  /** Insère ou met à jour un comptage (clé : date + poste + produit + moment). */
  upsertCount(input: CountUpsert): Promise<InventoryCount>;
  /** Marque validés tous les comptages d'un couple (date, poste, moment). */
  setCountsValidated(params: {
    date: string;
    workstationId: string;
    moment: CountMoment;
    validated: boolean;
    validatedBy: string;
  }): Promise<InventoryCount[]>;
  addPhoto(params: {
    inventoryCountId: string;
    url: string;
    takenAt: string;
  }): Promise<InventoryCount>;

  getProductionPlan(params: { forDate: string; workstationId: string }): Promise<ProductionPlan[]>;
  /**
   * Plans figés sur une période, tous postes confondus si `workstationId` est absent.
   * Utilisé par le delta technique : les pièces produites viennent des plans, dont
   * la date visée est le LENDEMAIN du comptage — les retrouver via les comptages
   * de la période donnerait un ensemble faux.
   */
  listProductionPlans(query: {
    from?: string;
    to?: string;
    workstationId?: string;
  }): Promise<ProductionPlan[]>;
  /** Écrit (et fige) le plan d'un couple (date visée, poste), en remplaçant l'existant. */
  replaceProductionPlan(params: {
    forDate: string;
    workstationId: string;
    lines: Array<Omit<ProductionPlan, 'id'>>;
  }): Promise<ProductionPlan[]>;

  listMaterialMovements(query: { from?: string; to?: string; workstationId?: string }): Promise<
    MaterialMovement[]
  >;
  addMaterialMovement(input: Omit<MaterialMovement, 'id'>): Promise<MaterialMovement>;

  appendAudit(entry: Omit<AuditEntry, 'id'>): Promise<AuditEntry>;
  listAudit(query: AuditQuery): Promise<AuditEntry[]>;
}
