/**
 * Store PostgreSQL — implémentation de production.
 *
 * Aucune génération de code : le SQL correspond exactement à
 * `migrations/001_init.sql`, rejoué au démarrage (script idempotent).
 */

import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import type {
  CountMoment,
  DayOfWeek,
  GeneralSettings,
  InventoryCount,
  Locale,
  ParMatrixEntry,
  Product,
  ProductionPlan,
  RoundingMode,
} from '@merisu/domain';
import { Pool } from 'pg';

import type {
  AuditEntry,
  AuditQuery,
  CountQuery,
  CountUpsert,
  MaterialMovement,
  Store,
} from './types.js';

const MIGRATIONS_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'migrations');

/** `pg` renvoie les NUMERIC en chaîne pour préserver la précision : on reconvertit. */
function num(value: unknown): number {
  if (value === null || value === undefined) return 0;
  return typeof value === 'number' ? value : Number.parseFloat(String(value));
}

function numOrNull(value: unknown): number | null {
  if (value === null || value === undefined) return null;
  return num(value);
}

/** Les colonnes DATE remontent en objet Date : on repasse en `YYYY-MM-DD`. */
function isoDate(value: unknown): string {
  if (value instanceof Date) {
    return `${value.getUTCFullYear()}-${String(value.getUTCMonth() + 1).padStart(2, '0')}-${String(
      value.getUTCDate(),
    ).padStart(2, '0')}`;
  }
  return String(value).slice(0, 10);
}

function isoTimestamp(value: unknown): string {
  if (value instanceof Date) return value.toISOString();
  return String(value);
}

export class PostgresStore implements Store {
  private readonly pool: Pool;

  constructor(connectionString: string) {
    this.pool = new Pool({ connectionString });
  }

  async init(): Promise<void> {
    const sql = readFileSync(join(MIGRATIONS_DIR, '001_init.sql'), 'utf8');
    await this.pool.query(sql);
  }

  async close(): Promise<void> {
    await this.pool.end();
  }

  // ── Paramètres ────────────────────────────────────────────────────────────

  async getSettings(): Promise<GeneralSettings> {
    const { rows } = await this.pool.query('SELECT * FROM inv_settings WHERE id = 1');
    const row = rows[0];
    if (!row) throw new Error('Ligne de paramètres absente : la migration a-t-elle été jouée ?');
    return {
      openingTime: row.opening_time,
      closingTime: row.closing_time,
      timezone: row.timezone,
      defaultLocale: row.default_locale as Locale,
      photoRequired: row.photo_required,
      photoPerProduct: row.photo_per_product,
      deltaTolerance: num(row.delta_tolerance),
    };
  }

  async updateSettings(patch: Partial<GeneralSettings>): Promise<GeneralSettings> {
    const current = await this.getSettings();
    const next: GeneralSettings = { ...current, ...patch };
    await this.pool.query(
      `UPDATE inv_settings
          SET opening_time = $1, closing_time = $2, timezone = $3, default_locale = $4,
              photo_required = $5, photo_per_product = $6, delta_tolerance = $7, updated_at = now()
        WHERE id = 1`,
      [
        next.openingTime,
        next.closingTime,
        next.timezone,
        next.defaultLocale,
        next.photoRequired,
        next.photoPerProduct,
        next.deltaTolerance,
      ],
    );
    return next;
  }

  // ── Produits ──────────────────────────────────────────────────────────────

  async listProducts(options?: { activeOnly?: boolean }): Promise<Product[]> {
    const { rows } = await this.pool.query(
      `SELECT * FROM inv_product ${options?.activeOnly ? 'WHERE active = TRUE' : ''}
        ORDER BY sort_order, code`,
    );
    return rows.map(mapProduct);
  }

  async getProduct(id: string): Promise<Product | null> {
    const { rows } = await this.pool.query('SELECT * FROM inv_product WHERE id = $1', [id]);
    return rows[0] ? mapProduct(rows[0]) : null;
  }

  async upsertProduct(product: Product): Promise<Product> {
    const { rows } = await this.pool.query(
      `INSERT INTO inv_product
         (id, code, name, unit, active, waste_factor, rounding_step, rounding_mode, recipe_ref, sort_order)
       VALUES ($1, $2, $3::jsonb, $4, $5, $6, $7, $8, $9, $10)
       ON CONFLICT (id) DO UPDATE SET
         code = EXCLUDED.code, name = EXCLUDED.name, unit = EXCLUDED.unit,
         active = EXCLUDED.active, waste_factor = EXCLUDED.waste_factor,
         rounding_step = EXCLUDED.rounding_step, rounding_mode = EXCLUDED.rounding_mode,
         recipe_ref = EXCLUDED.recipe_ref, sort_order = EXCLUDED.sort_order
       RETURNING *`,
      [
        product.id,
        product.code,
        JSON.stringify(product.name),
        product.unit,
        product.active,
        product.wasteFactor,
        product.roundingStep,
        product.roundingMode,
        product.recipeRef,
        product.sortOrder,
      ],
    );
    return mapProduct(rows[0]);
  }

  // ── Matrice des seuils ────────────────────────────────────────────────────

  async listParMatrix(): Promise<ParMatrixEntry[]> {
    const { rows } = await this.pool.query('SELECT * FROM inv_par_matrix');
    return rows.map(mapParEntry);
  }

  async upsertParEntries(
    entries: Array<{
      productId: string;
      dayOfWeek: DayOfWeek;
      requiredPieces: number | null;
      workstationId: string | null;
    }>,
  ): Promise<ParMatrixEntry[]> {
    const client = await this.pool.connect();
    try {
      await client.query('BEGIN');
      for (const entry of entries) {
        if (entry.requiredPieces === null) {
          await client.query(
            `DELETE FROM inv_par_matrix
              WHERE product_id = $1 AND day_of_week = $2
                AND COALESCE(workstation_id, '') = COALESCE($3, '')`,
            [entry.productId, entry.dayOfWeek, entry.workstationId],
          );
          continue;
        }
        await client.query(
          `INSERT INTO inv_par_matrix (id, product_id, day_of_week, required_pieces, workstation_id)
           VALUES ($1, $2, $3, $4, $5)
           ON CONFLICT (product_id, day_of_week, COALESCE(workstation_id, ''))
           DO UPDATE SET required_pieces = EXCLUDED.required_pieces`,
          [
            randomUUID(),
            entry.productId,
            entry.dayOfWeek,
            entry.requiredPieces,
            entry.workstationId,
          ],
        );
      }
      await client.query('COMMIT');
    } catch (error) {
      await client.query('ROLLBACK');
      throw error;
    } finally {
      client.release();
    }
    return this.listParMatrix();
  }

  // ── Comptages ─────────────────────────────────────────────────────────────

  async findCounts(query: CountQuery): Promise<InventoryCount[]> {
    const conditions: string[] = [];
    const params: unknown[] = [];

    const push = (sql: string, value: unknown): void => {
      params.push(value);
      conditions.push(sql.replace('?', `$${params.length}`));
    };

    if (query.date) push('c.business_date = ?', query.date);
    if (query.from) push('c.business_date >= ?', query.from);
    if (query.to) push('c.business_date <= ?', query.to);
    if (query.workstationId) push('c.workstation_id = ?', query.workstationId);
    if (query.moment) push('c.moment = ?', query.moment);
    if (query.productId) push('c.product_id = ?', query.productId);

    const where = conditions.length ? `WHERE ${conditions.join(' AND ')}` : '';
    const { rows } = await this.pool.query(
      `SELECT c.*,
              COALESCE(
                json_agg(json_build_object('id', p.id, 'url', p.url, 'takenAt', p.taken_at)
                         ORDER BY p.taken_at)
                FILTER (WHERE p.id IS NOT NULL), '[]'
              ) AS photos
         FROM inv_count c
         LEFT JOIN inv_count_photo p ON p.inventory_count_id = c.id
         ${where}
        GROUP BY c.id
        ORDER BY c.business_date, c.product_id`,
      params,
    );
    return rows.map(mapCount);
  }

  async getCount(id: string): Promise<InventoryCount | null> {
    const { rows } = await this.pool.query(
      `SELECT c.*,
              COALESCE(
                json_agg(json_build_object('id', p.id, 'url', p.url, 'takenAt', p.taken_at)
                         ORDER BY p.taken_at)
                FILTER (WHERE p.id IS NOT NULL), '[]'
              ) AS photos
         FROM inv_count c
         LEFT JOIN inv_count_photo p ON p.inventory_count_id = c.id
        WHERE c.id = $1
        GROUP BY c.id`,
      [id],
    );
    return rows[0] ? mapCount(rows[0]) : null;
  }

  async upsertCount(input: CountUpsert): Promise<InventoryCount> {
    const { rows } = await this.pool.query(
      `INSERT INTO inv_count
         (id, business_date, workstation_id, consultant_id, product_id, moment, qty, produced_qty)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
       ON CONFLICT (business_date, workstation_id, product_id, moment) DO UPDATE SET
         qty = EXCLUDED.qty,
         consultant_id = EXCLUDED.consultant_id,
         produced_qty = COALESCE(EXCLUDED.produced_qty, inv_count.produced_qty),
         updated_at = now()
       RETURNING id`,
      [
        randomUUID(),
        input.date,
        input.workstationId,
        input.consultantId,
        input.productId,
        input.moment,
        input.qty,
        input.producedQty ?? null,
      ],
    );
    const saved = await this.getCount(rows[0].id);
    if (!saved) throw new Error('Comptage introuvable après enregistrement');
    return saved;
  }

  async setCountsValidated(params: {
    date: string;
    workstationId: string;
    moment: CountMoment;
    validated: boolean;
    validatedBy: string;
  }): Promise<InventoryCount[]> {
    const { rows } = await this.pool.query(
      `UPDATE inv_count
          SET validated = $4,
              validated_at = CASE WHEN $4 THEN now() ELSE NULL END,
              validated_by = CASE WHEN $4 THEN $5::text ELSE NULL END,
              updated_at = now()
        WHERE business_date = $1 AND workstation_id = $2 AND moment = $3
        RETURNING id`,
      [params.date, params.workstationId, params.moment, params.validated, params.validatedBy],
    );

    const result: InventoryCount[] = [];
    for (const row of rows) {
      const count = await this.getCount(row.id);
      if (count) result.push(count);
    }
    return result;
  }

  async addPhoto(params: {
    inventoryCountId: string;
    url: string;
    takenAt: string;
  }): Promise<InventoryCount> {
    await this.pool.query(
      `INSERT INTO inv_count_photo (id, inventory_count_id, url, taken_at)
       VALUES ($1, $2, $3, $4)`,
      [randomUUID(), params.inventoryCountId, params.url, params.takenAt],
    );
    const count = await this.getCount(params.inventoryCountId);
    if (!count) throw new Error(`Comptage introuvable : ${params.inventoryCountId}`);
    return count;
  }

  // ── Plans de production ───────────────────────────────────────────────────

  async getProductionPlan(params: {
    forDate: string;
    workstationId: string;
  }): Promise<ProductionPlan[]> {
    const { rows } = await this.pool.query(
      `SELECT * FROM inv_production_plan
        WHERE for_date = $1 AND workstation_id = $2
        ORDER BY product_id`,
      [params.forDate, params.workstationId],
    );
    return rows.map(mapPlan);
  }

  async listProductionPlans(query: {
    from?: string;
    to?: string;
    workstationId?: string;
  }): Promise<ProductionPlan[]> {
    const conditions: string[] = [];
    const params: unknown[] = [];
    const push = (sql: string, value: unknown): void => {
      params.push(value);
      conditions.push(sql.replace('?', `$${params.length}`));
    };

    if (query.from) push('for_date >= ?', query.from);
    if (query.to) push('for_date <= ?', query.to);
    if (query.workstationId) push('workstation_id = ?', query.workstationId);

    const where = conditions.length ? `WHERE ${conditions.join(' AND ')}` : '';
    const { rows } = await this.pool.query(
      `SELECT * FROM inv_production_plan ${where} ORDER BY for_date, product_id`,
      params,
    );
    return rows.map(mapPlan);
  }

  async replaceProductionPlan(params: {
    forDate: string;
    workstationId: string;
    lines: Array<Omit<ProductionPlan, 'id'>>;
  }): Promise<ProductionPlan[]> {
    const client = await this.pool.connect();
    try {
      await client.query('BEGIN');
      await client.query(
        'DELETE FROM inv_production_plan WHERE for_date = $1 AND workstation_id = $2',
        [params.forDate, params.workstationId],
      );
      for (const line of params.lines) {
        await client.query(
          `INSERT INTO inv_production_plan
             (id, for_date, product_id, workstation_id, required_pieces, closing_stock,
              qty_to_produce, missing_threshold, computed_at, status)
           VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)`,
          [
            randomUUID(),
            line.forDate,
            line.productId,
            line.workstationId,
            line.requiredPieces,
            line.closingStock,
            line.qtyToProduce,
            line.missingThreshold,
            line.computedAt,
            line.status,
          ],
        );
      }
      await client.query('COMMIT');
    } catch (error) {
      await client.query('ROLLBACK');
      throw error;
    } finally {
      client.release();
    }
    return this.getProductionPlan(params);
  }

  // ── Consommation réelle de matières ───────────────────────────────────────

  async listMaterialMovements(query: {
    from?: string;
    to?: string;
    workstationId?: string;
  }): Promise<MaterialMovement[]> {
    const conditions: string[] = [];
    const params: unknown[] = [];
    const push = (sql: string, value: unknown): void => {
      params.push(value);
      conditions.push(sql.replace('?', `$${params.length}`));
    };

    if (query.from) push('business_date >= ?', query.from);
    if (query.to) push('business_date <= ?', query.to);
    if (query.workstationId) push('workstation_id = ?', query.workstationId);

    const where = conditions.length ? `WHERE ${conditions.join(' AND ')}` : '';
    const { rows } = await this.pool.query(
      `SELECT * FROM inv_material_movement ${where} ORDER BY business_date, material_id`,
      params,
    );
    return rows.map(mapMovement);
  }

  async addMaterialMovement(input: Omit<MaterialMovement, 'id'>): Promise<MaterialMovement> {
    const { rows } = await this.pool.query(
      `INSERT INTO inv_material_movement
         (id, business_date, material_id, real_qty, workstation_id, recorded_by, recorded_at, note)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
       RETURNING *`,
      [
        randomUUID(),
        input.date,
        input.materialId,
        input.realQty,
        input.workstationId,
        input.recordedBy,
        input.recordedAt,
        input.note,
      ],
    );
    return mapMovement(rows[0]);
  }

  // ── Audit ─────────────────────────────────────────────────────────────────

  async appendAudit(entry: Omit<AuditEntry, 'id'>): Promise<AuditEntry> {
    const { rows } = await this.pool.query(
      `INSERT INTO inv_audit (id, at, actor_id, actor_role, action, workstation_id, business_date, details)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb)
       RETURNING *`,
      [
        randomUUID(),
        entry.at,
        entry.actorId,
        entry.actorRole,
        entry.action,
        entry.workstationId,
        entry.businessDate,
        JSON.stringify(entry.details ?? {}),
      ],
    );
    return mapAudit(rows[0]);
  }

  async listAudit(query: AuditQuery): Promise<AuditEntry[]> {
    const conditions: string[] = [];
    const params: unknown[] = [];
    const push = (sql: string, value: unknown): void => {
      params.push(value);
      conditions.push(sql.replace('?', `$${params.length}`));
    };

    if (query.from) push('business_date >= ?', query.from);
    if (query.to) push('business_date <= ?', query.to);
    if (query.workstationId) push('workstation_id = ?', query.workstationId);
    if (query.actorId) push('actor_id = ?', query.actorId);

    const where = conditions.length ? `WHERE ${conditions.join(' AND ')}` : '';
    params.push(query.limit ?? 500);

    const { rows } = await this.pool.query(
      `SELECT * FROM inv_audit ${where} ORDER BY at DESC LIMIT $${params.length}`,
      params,
    );
    return rows.map(mapAudit);
  }
}

// ── Correspondance lignes SQL → objets du domaine ───────────────────────────

function mapProduct(row: any): Product {
  return {
    id: row.id,
    code: row.code,
    name: typeof row.name === 'string' ? JSON.parse(row.name) : (row.name ?? {}),
    unit: row.unit,
    active: row.active,
    wasteFactor: num(row.waste_factor),
    roundingStep: num(row.rounding_step),
    roundingMode: row.rounding_mode as RoundingMode,
    recipeRef: row.recipe_ref,
    sortOrder: row.sort_order,
  };
}

function mapParEntry(row: any): ParMatrixEntry {
  return {
    id: row.id,
    productId: row.product_id,
    dayOfWeek: row.day_of_week as DayOfWeek,
    requiredPieces: num(row.required_pieces),
    workstationId: row.workstation_id,
  };
}

function mapCount(row: any): InventoryCount {
  const photos = Array.isArray(row.photos) ? row.photos : [];
  return {
    id: row.id,
    date: isoDate(row.business_date),
    workstationId: row.workstation_id,
    consultantId: row.consultant_id,
    productId: row.product_id,
    moment: row.moment as CountMoment,
    qty: num(row.qty),
    producedQty: numOrNull(row.produced_qty),
    validated: row.validated,
    validatedAt: row.validated_at ? isoTimestamp(row.validated_at) : null,
    validatedBy: row.validated_by,
    photos: photos.map((p: any) => ({
      id: p.id,
      inventoryCountId: row.id,
      url: p.url,
      takenAt: isoTimestamp(p.takenAt),
    })),
    createdAt: isoTimestamp(row.created_at),
    updatedAt: isoTimestamp(row.updated_at),
  };
}

function mapPlan(row: any): ProductionPlan {
  return {
    id: row.id,
    forDate: isoDate(row.for_date),
    productId: row.product_id,
    workstationId: row.workstation_id,
    requiredPieces: num(row.required_pieces),
    closingStock: num(row.closing_stock),
    qtyToProduce: num(row.qty_to_produce),
    computedAt: isoTimestamp(row.computed_at),
    status: row.status,
    missingThreshold: row.missing_threshold,
  };
}

function mapMovement(row: any): MaterialMovement {
  return {
    id: row.id,
    date: isoDate(row.business_date),
    materialId: row.material_id,
    realQty: num(row.real_qty),
    workstationId: row.workstation_id,
    recordedBy: row.recorded_by,
    recordedAt: isoTimestamp(row.recorded_at),
    note: row.note,
  };
}

function mapAudit(row: any): AuditEntry {
  return {
    id: row.id,
    at: isoTimestamp(row.at),
    actorId: row.actor_id,
    actorRole: row.actor_role,
    action: row.action,
    workstationId: row.workstation_id,
    businessDate: row.business_date ? isoDate(row.business_date) : null,
    details: typeof row.details === 'string' ? JSON.parse(row.details) : (row.details ?? {}),
  };
}
