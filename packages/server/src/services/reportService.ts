/**
 * Rapports admin (§6) — delta technique, écarts nets, vue matricielle, export CSV.
 *
 * Principe directeur : les résultats sont INDICATIFS tant que les données ne sont
 * pas complètes. Chaque rapport porte un drapeau `partial` et le détail de ce qui
 * manque, pour ne jamais laisser conclure sur des données incomplètes.
 */

import {
  computeDailyNet,
  computeMaterialDeltas,
  computeTheoreticalConsumption,
  dateRange,
  dayOfWeekOf,
  resolveI18nText,
  summarizeDeltas,
  type DailyNet,
  type DayOfWeek,
  type Locale,
  type MaterialDelta,
  type Product,
} from '@merisu/domain';

import type { RecipeService } from '../adapters/recipeService.js';
import type { Store } from '../store/types.js';

export interface ReportPeriod {
  from: string;
  to: string;
  workstationId?: string;
}

export interface DailyNetReport extends ReportPeriod {
  rows: DailyNet[];
  /** Total de consommation nette par produit, jours incomplets exclus. */
  totalsByProduct: Record<string, { total: number; skippedDays: number }>;
  partial: boolean;
  /** Couples (date, produit) sans comptage complet. */
  missing: Array<{ date: string; productId: string; missing: 'OPEN' | 'CLOSE' | 'BOTH' }>;
}

export interface DeltaReport extends ReportPeriod {
  deltas: MaterialDelta[];
  summary: { materials: number; outOfTolerance: number; partial: boolean };
  tolerance: number;
  /** Pièces produites retenues pour le calcul théorique, par produit. */
  producedByProduct: Record<string, number>;
  /** Produits sans recette connue → le théorique est sous-estimé. */
  productsWithoutRecipe: string[];
  /** Matières sans consommation réelle saisie. */
  materialsWithoutRealData: string[];
  partial: boolean;
}

/** Vue matricielle jour × produit (§6). */
export interface MatrixReport extends ReportPeriod {
  days: Array<{ date: string; dayOfWeek: DayOfWeek }>;
  products: Array<{ id: string; code: string; unit: string }>;
  /** `cells[productId][date]` — null quand la donnée manque. */
  cells: Record<string, Record<string, MatrixCell>>;
}

export interface MatrixCell {
  required: number | null;
  opening: number | null;
  closing: number | null;
  produced: number | null;
  net: number | null;
}

export class ReportService {
  constructor(
    private readonly store: Store,
    private readonly recipes: RecipeService,
  ) {}

  /** §5.1 sur une période — écart net par produit et par jour. */
  async dailyNetReport(period: ReportPeriod): Promise<DailyNetReport> {
    const products = await this.store.listProducts();
    const counts = await this.store.findCounts({
      from: period.from,
      to: period.to,
      ...(period.workstationId ? { workstationId: period.workstationId } : {}),
    });

    const rows: DailyNet[] = [];
    const missing: DailyNetReport['missing'] = [];
    const totalsByProduct: DailyNetReport['totalsByProduct'] = {};

    for (const date of dateRange(period.from, period.to)) {
      for (const product of products) {
        const dayCounts = counts.filter((c) => c.date === date && c.productId === product.id);
        if (dayCounts.length === 0) continue; // journée sans activité : on ne l'invente pas

        const opening = dayCounts.find((c) => c.moment === 'OPEN_0800');
        const closing = dayCounts.find((c) => c.moment === 'CLOSE_2200');

        const net = computeDailyNet({
          date,
          productId: product.id,
          workstationId: period.workstationId ?? (dayCounts[0]!.workstationId),
          openingStock: opening?.qty ?? null,
          closingStock: closing?.qty ?? null,
          producedQty: sumProduced(dayCounts),
        });
        rows.push(net);

        const bucket = (totalsByProduct[product.id] ??= { total: 0, skippedDays: 0 });
        if (net.netConsumption === null) {
          bucket.skippedDays += 1;
          missing.push({
            date,
            productId: product.id,
            missing: !opening && !closing ? 'BOTH' : !opening ? 'OPEN' : 'CLOSE',
          });
        } else {
          bucket.total = round9(bucket.total + net.netConsumption);
        }
      }
    }

    return { ...period, rows, totalsByProduct, missing, partial: missing.length > 0 };
  }

  /**
   * §6 — delta technique : recettes × pièces produites vs consommation réelle.
   *
   * Pièces produites retenues : les plans FIGÉS de la période (ce qui a été
   * demandé à la production). Si un plan manque, le théorique est sous-estimé et
   * le rapport est marqué partiel.
   */
  async deltaReport(period: ReportPeriod): Promise<DeltaReport> {
    const settings = await this.store.getSettings();
    const products = await this.store.listProducts();

    const plans = await this.store.listProductionPlans({
      from: period.from,
      to: period.to,
      ...(period.workstationId ? { workstationId: period.workstationId } : {}),
    });

    const producedByProduct: Record<string, number> = {};
    for (const line of plans) {
      producedByProduct[line.productId] =
        round9((producedByProduct[line.productId] ?? 0) + line.qtyToProduce);
    }
    const anyPlan = plans.length > 0;

    const recipes = await this.recipes.getRecipes(products.map((p) => p.id));
    const { byMaterial, productsWithoutRecipe } = computeTheoreticalConsumption({
      produced: Object.entries(producedByProduct).map(([productId, producedQty]) => ({
        productId,
        producedQty,
      })),
      recipes,
    });

    const movements = await this.store.listMaterialMovements({
      from: period.from,
      to: period.to,
      ...(period.workstationId ? { workstationId: period.workstationId } : {}),
    });

    const realByMaterial: Record<string, number> = {};
    for (const movement of movements) {
      realByMaterial[movement.materialId] =
        round9((realByMaterial[movement.materialId] ?? 0) + movement.realQty);
    }

    const deltas = computeMaterialDeltas({
      theoreticalByMaterial: byMaterial,
      realByMaterial,
      tolerance: settings.deltaTolerance,
    });

    const materialsWithoutRealData = deltas.filter((d) => d.partial).map((d) => d.materialId);

    return {
      ...period,
      deltas,
      summary: summarizeDeltas(deltas),
      tolerance: settings.deltaTolerance,
      producedByProduct,
      productsWithoutRecipe,
      materialsWithoutRealData,
      partial:
        !anyPlan ||
        productsWithoutRecipe.length > 0 ||
        materialsWithoutRealData.length > 0 ||
        deltas.length === 0,
    };
  }

  /** Vue matricielle jour × produit : seuil, ouverture, clôture, écart net. */
  async matrixReport(period: ReportPeriod): Promise<MatrixReport> {
    const [products, parMatrix, counts] = await Promise.all([
      this.store.listProducts({ activeOnly: true }),
      this.store.listParMatrix(),
      this.store.findCounts({
        from: period.from,
        to: period.to,
        ...(period.workstationId ? { workstationId: period.workstationId } : {}),
      }),
    ]);

    const days = dateRange(period.from, period.to).map((date) => ({
      date,
      dayOfWeek: dayOfWeekOf(date),
    }));

    const cells: MatrixReport['cells'] = {};

    for (const product of products) {
      cells[product.id] = {};
      for (const { date, dayOfWeek } of days) {
        const dayCounts = counts.filter((c) => c.date === date && c.productId === product.id);
        const opening = dayCounts.find((c) => c.moment === 'OPEN_0800')?.qty ?? null;
        const closing = dayCounts.find((c) => c.moment === 'CLOSE_2200')?.qty ?? null;
        const produced = sumProduced(dayCounts);

        const par = parMatrix.find(
          (e) =>
            e.productId === product.id &&
            e.dayOfWeek === dayOfWeek &&
            (period.workstationId
              ? e.workstationId === period.workstationId || e.workstationId === null
              : e.workstationId === null),
        );

        const net =
          opening === null || closing === null ? null : round9(opening + produced - closing);

        cells[product.id]![date] = {
          required: par ? par.requiredPieces : null,
          opening,
          closing,
          produced: produced || null,
          net,
        };
      }
    }

    return {
      ...period,
      days,
      products: products.map((p) => ({ id: p.id, code: p.code, unit: p.unit })),
      cells,
    };
  }

  /** Export CSV du rapport de delta (§6 — export CSV/Excel). */
  async deltaReportCsv(period: ReportPeriod, locale: Locale = 'fr'): Promise<string> {
    const report = await this.deltaReport(period);
    const materials = await this.recipes.listMaterials();
    const nameById = new Map(materials.map((m) => [m.id, m]));
    const settings = await this.store.getSettings();

    const header = [
      'material_id',
      'material_name',
      'unit',
      'theoretical_qty',
      'real_qty',
      'delta',
      'delta_pct',
      'out_of_tolerance',
      'partial',
    ];

    const lines = report.deltas.map((delta) => {
      const material = nameById.get(delta.materialId);
      return [
        delta.materialId,
        resolveI18nText(material?.name, locale, settings.defaultLocale, delta.materialId),
        material?.unit ?? '',
        String(delta.theoreticalQty),
        delta.realQty === null ? '' : String(delta.realQty),
        delta.delta === null ? '' : String(delta.delta),
        delta.deltaPct === null ? '' : (delta.deltaPct * 100).toFixed(2),
        delta.outOfTolerance ? '1' : '0',
        delta.partial ? '1' : '0',
      ];
    });

    return toCsv([header, ...lines]);
  }

  /** Export CSV de la vue matricielle jour × produit. */
  async matrixReportCsv(period: ReportPeriod, locale: Locale = 'fr'): Promise<string> {
    const report = await this.matrixReport(period);
    const products = await this.store.listProducts({ activeOnly: true });
    const settings = await this.store.getSettings();
    const byId = new Map(products.map((p) => [p.id, p]));

    const header = [
      'date',
      'day_of_week',
      'product_code',
      'product_name',
      'unit',
      'required_pieces',
      'opening_stock',
      'closing_stock',
      'produced',
      'net_consumption',
    ];

    const lines: string[][] = [];
    for (const { date, dayOfWeek } of report.days) {
      for (const product of report.products) {
        const cell = report.cells[product.id]?.[date];
        if (!cell) continue;
        const full = byId.get(product.id);
        lines.push([
          date,
          dayOfWeek,
          product.code,
          resolveI18nText(full?.name, locale, settings.defaultLocale, product.code),
          product.unit,
          cell.required === null ? '' : String(cell.required),
          cell.opening === null ? '' : String(cell.opening),
          cell.closing === null ? '' : String(cell.closing),
          cell.produced === null ? '' : String(cell.produced),
          cell.net === null ? '' : String(cell.net),
        ]);
      }
    }

    return toCsv([header, ...lines]);
  }

}

function sumProduced(counts: Array<{ producedQty?: number | null }>): number {
  return counts.reduce((total, c) => total + (c.producedQty ?? 0), 0);
}

/** Sérialisation CSV avec échappement RFC 4180 (Excel-compatible). */
export function toCsv(rows: string[][]): string {
  return rows
    .map((row) =>
      row
        .map((cell) => {
          const value = cell ?? '';
          return /[",\n;]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
        })
        .join(','),
    )
    .join('\r\n');
}

function round9(value: number): number {
  return Math.round(value * 1e9) / 1e9;
}

export type { Product };
