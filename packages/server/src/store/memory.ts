/**
 * Store en mémoire, avec persistance optionnelle dans un fichier JSON.
 *
 * Rôle : faire tourner et démontrer le module sans installer PostgreSQL, et
 * servir de base aux tests. La production utilise `PostgresStore` — les deux
 * implémentent strictement la même interface `Store`.
 */

import { randomUUID } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

import type {
  GeneralSettings,
  InventoryCount,
  ParMatrixEntry,
  Product,
  ProductionPlan,
} from '@merisu/domain';

import { DEFAULT_SETTINGS } from './defaults.js';
import type {
  AuditEntry,
  AuditQuery,
  CountQuery,
  CountUpsert,
  MaterialMovement,
  Store,
} from './types.js';

interface Snapshot {
  settings: GeneralSettings;
  products: Product[];
  parMatrix: ParMatrixEntry[];
  counts: InventoryCount[];
  plans: ProductionPlan[];
  movements: MaterialMovement[];
  audit: AuditEntry[];
}

function emptySnapshot(): Snapshot {
  return {
    settings: { ...DEFAULT_SETTINGS },
    products: [],
    parMatrix: [],
    counts: [],
    plans: [],
    movements: [],
    audit: [],
  };
}

export class MemoryStore implements Store {
  private data: Snapshot = emptySnapshot();

  /** Fichier de persistance ; undefined = purement volatil (tests). */
  constructor(private readonly snapshotFile?: string) {}

  async init(): Promise<void> {
    if (this.snapshotFile && existsSync(this.snapshotFile)) {
      try {
        const raw = readFileSync(this.snapshotFile, 'utf8');
        this.data = { ...emptySnapshot(), ...(JSON.parse(raw) as Partial<Snapshot>) };
      } catch (error) {
        // Un fichier corrompu ne doit pas empêcher le poste de démarrer.
        console.warn(`[store] snapshot illisible, démarrage à vide : ${String(error)}`);
        this.data = emptySnapshot();
      }
    }
  }

  async close(): Promise<void> {
    this.persist();
  }

  private persist(): void {
    if (!this.snapshotFile) return;
    mkdirSync(dirname(this.snapshotFile), { recursive: true });
    writeFileSync(this.snapshotFile, JSON.stringify(this.data, null, 2), 'utf8');
  }

  // ── Paramètres ────────────────────────────────────────────────────────────

  async getSettings(): Promise<GeneralSettings> {
    return { ...this.data.settings };
  }

  async updateSettings(patch: Partial<GeneralSettings>): Promise<GeneralSettings> {
    this.data.settings = { ...this.data.settings, ...patch };
    this.persist();
    return { ...this.data.settings };
  }

  // ── Produits ──────────────────────────────────────────────────────────────

  async listProducts(options?: { activeOnly?: boolean }): Promise<Product[]> {
    const products = options?.activeOnly
      ? this.data.products.filter((p) => p.active)
      : this.data.products;
    return [...products].sort((a, b) => a.sortOrder - b.sortOrder || a.code.localeCompare(b.code));
  }

  async getProduct(id: string): Promise<Product | null> {
    return this.data.products.find((p) => p.id === id) ?? null;
  }

  async upsertProduct(product: Product): Promise<Product> {
    const index = this.data.products.findIndex((p) => p.id === product.id);
    if (index >= 0) this.data.products[index] = { ...product };
    else this.data.products.push({ ...product });
    this.persist();
    return { ...product };
  }

  // ── Matrice des seuils ────────────────────────────────────────────────────

  async listParMatrix(): Promise<ParMatrixEntry[]> {
    return this.data.parMatrix.map((e) => ({ ...e }));
  }

  async upsertParEntries(
    entries: Array<{
      productId: string;
      dayOfWeek: ParMatrixEntry['dayOfWeek'];
      requiredPieces: number | null;
      workstationId: string | null;
    }>,
  ): Promise<ParMatrixEntry[]> {
    for (const entry of entries) {
      const index = this.data.parMatrix.findIndex(
        (e) =>
          e.productId === entry.productId &&
          e.dayOfWeek === entry.dayOfWeek &&
          e.workstationId === entry.workstationId,
      );

      // requiredPieces = null ⇒ « pas de seuil défini », donc suppression de la ligne.
      if (entry.requiredPieces === null) {
        if (index >= 0) this.data.parMatrix.splice(index, 1);
        continue;
      }

      if (index >= 0) {
        this.data.parMatrix[index]!.requiredPieces = entry.requiredPieces;
      } else {
        this.data.parMatrix.push({
          id: randomUUID(),
          productId: entry.productId,
          dayOfWeek: entry.dayOfWeek,
          requiredPieces: entry.requiredPieces,
          workstationId: entry.workstationId,
        });
      }
    }
    this.persist();
    return this.listParMatrix();
  }

  // ── Comptages ─────────────────────────────────────────────────────────────

  async findCounts(query: CountQuery): Promise<InventoryCount[]> {
    return this.data.counts
      .filter((c) => {
        if (query.date && c.date !== query.date) return false;
        if (query.from && c.date < query.from) return false;
        if (query.to && c.date > query.to) return false;
        if (query.workstationId && c.workstationId !== query.workstationId) return false;
        if (query.moment && c.moment !== query.moment) return false;
        if (query.productId && c.productId !== query.productId) return false;
        return true;
      })
      .map(cloneCount)
      .sort((a, b) => a.date.localeCompare(b.date) || a.productId.localeCompare(b.productId));
  }

  async getCount(id: string): Promise<InventoryCount | null> {
    const found = this.data.counts.find((c) => c.id === id);
    return found ? cloneCount(found) : null;
  }

  async upsertCount(input: CountUpsert): Promise<InventoryCount> {
    const now = new Date().toISOString();
    const existing = this.data.counts.find(
      (c) =>
        c.date === input.date &&
        c.workstationId === input.workstationId &&
        c.productId === input.productId &&
        c.moment === input.moment,
    );

    if (existing) {
      existing.qty = input.qty;
      existing.consultantId = input.consultantId;
      if (input.producedQty !== undefined) existing.producedQty = input.producedQty;
      existing.updatedAt = now;
      this.persist();
      return cloneCount(existing);
    }

    const created: InventoryCount = {
      id: randomUUID(),
      date: input.date,
      workstationId: input.workstationId,
      consultantId: input.consultantId,
      productId: input.productId,
      moment: input.moment,
      qty: input.qty,
      producedQty: input.producedQty ?? null,
      validated: false,
      validatedAt: null,
      validatedBy: null,
      photos: [],
      createdAt: now,
      updatedAt: now,
    };
    this.data.counts.push(created);
    this.persist();
    return cloneCount(created);
  }

  async setCountsValidated(params: {
    date: string;
    workstationId: string;
    moment: InventoryCount['moment'];
    validated: boolean;
    validatedBy: string;
  }): Promise<InventoryCount[]> {
    const now = new Date().toISOString();
    const touched: InventoryCount[] = [];

    for (const count of this.data.counts) {
      if (
        count.date !== params.date ||
        count.workstationId !== params.workstationId ||
        count.moment !== params.moment
      ) {
        continue;
      }
      count.validated = params.validated;
      count.validatedAt = params.validated ? now : null;
      count.validatedBy = params.validated ? params.validatedBy : null;
      count.updatedAt = now;
      touched.push(cloneCount(count));
    }

    this.persist();
    return touched;
  }

  async addPhoto(params: {
    inventoryCountId: string;
    url: string;
    takenAt: string;
  }): Promise<InventoryCount> {
    const count = this.data.counts.find((c) => c.id === params.inventoryCountId);
    if (!count) throw new Error(`Comptage introuvable : ${params.inventoryCountId}`);

    count.photos.push({
      id: randomUUID(),
      inventoryCountId: count.id,
      url: params.url,
      takenAt: params.takenAt,
    });
    count.updatedAt = new Date().toISOString();
    this.persist();
    return cloneCount(count);
  }

  // ── Plans de production ───────────────────────────────────────────────────

  async getProductionPlan(params: {
    forDate: string;
    workstationId: string;
  }): Promise<ProductionPlan[]> {
    return this.data.plans
      .filter((p) => p.forDate === params.forDate && p.workstationId === params.workstationId)
      .map((p) => ({ ...p }));
  }

  async listProductionPlans(query: {
    from?: string;
    to?: string;
    workstationId?: string;
  }): Promise<ProductionPlan[]> {
    return this.data.plans
      .filter((p) => {
        if (query.from && p.forDate < query.from) return false;
        if (query.to && p.forDate > query.to) return false;
        if (query.workstationId && p.workstationId !== query.workstationId) return false;
        return true;
      })
      .map((p) => ({ ...p }))
      .sort((a, b) => a.forDate.localeCompare(b.forDate) || a.productId.localeCompare(b.productId));
  }

  async replaceProductionPlan(params: {
    forDate: string;
    workstationId: string;
    lines: Array<Omit<ProductionPlan, 'id'>>;
  }): Promise<ProductionPlan[]> {
    this.data.plans = this.data.plans.filter(
      (p) => !(p.forDate === params.forDate && p.workstationId === params.workstationId),
    );
    const created = params.lines.map((line) => ({ ...line, id: randomUUID() }));
    this.data.plans.push(...created);
    this.persist();
    return created;
  }

  // ── Consommation réelle de matières ───────────────────────────────────────

  async listMaterialMovements(query: {
    from?: string;
    to?: string;
    workstationId?: string;
  }): Promise<MaterialMovement[]> {
    return this.data.movements
      .filter((m) => {
        if (query.from && m.date < query.from) return false;
        if (query.to && m.date > query.to) return false;
        if (query.workstationId && m.workstationId !== query.workstationId) return false;
        return true;
      })
      .map((m) => ({ ...m }));
  }

  async addMaterialMovement(input: Omit<MaterialMovement, 'id'>): Promise<MaterialMovement> {
    const created: MaterialMovement = { ...input, id: randomUUID() };
    this.data.movements.push(created);
    this.persist();
    return { ...created };
  }

  // ── Audit ─────────────────────────────────────────────────────────────────

  async appendAudit(entry: Omit<AuditEntry, 'id'>): Promise<AuditEntry> {
    const created: AuditEntry = { ...entry, id: randomUUID() };
    this.data.audit.push(created);
    this.persist();
    return { ...created };
  }

  async listAudit(query: AuditQuery): Promise<AuditEntry[]> {
    const rows = this.data.audit
      .filter((entry) => {
        if (query.from && (entry.businessDate ?? entry.at.slice(0, 10)) < query.from) return false;
        if (query.to && (entry.businessDate ?? entry.at.slice(0, 10)) > query.to) return false;
        if (query.workstationId && entry.workstationId !== query.workstationId) return false;
        if (query.actorId && entry.actorId !== query.actorId) return false;
        return true;
      })
      .sort((a, b) => b.at.localeCompare(a.at));

    return rows.slice(0, query.limit ?? 500).map((e) => ({ ...e }));
  }
}

function cloneCount(count: InventoryCount): InventoryCount {
  return { ...count, photos: count.photos.map((p) => ({ ...p })) };
}
