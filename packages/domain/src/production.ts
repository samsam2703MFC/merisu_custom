/**
 * §5.2 — Production à réaliser pour le lendemain matin.
 *
 *   Requis(demain)  = ParMatrix[produit, jour_de_demain].required_pieces
 *   Stock_clôture   = comptage CLOSE_2200 validé du jour
 *   À_produire_brut = max(0, Requis(demain) − Stock_clôture)
 *   À_produire      = arrondi( À_produire_brut × (1 + waste_factor), rounding )
 *
 * Calculé à la validation du stock du soir, figé et horodaté.
 */

import { dayOfWeekOf, nextDay } from './date.js';
import { roundQuantity } from './rounding.js';
import type { DayOfWeek, ParMatrixEntry, Product } from './types.js';

export interface ProductionLineInput {
  product: Product;
  /** Stock compté à 22:00. null = non saisi (traité comme 0 mais signalé). */
  closingStock: number | null;
  /** Seuil du jour visé. null = aucun seuil défini → 0 + avertissement admin. */
  requiredPieces: number | null;
}

export interface ProductionLineResult {
  productId: string;
  productCode: string;
  requiredPieces: number;
  closingStock: number;
  /** Besoin avant application du facteur de perte et de l'arrondi. */
  rawQtyToProduce: number;
  /** Résultat final, à produire demain matin. */
  qtyToProduce: number;
  /** Aucun seuil défini pour (produit, jour) → à corriger en admin. */
  missingThreshold: boolean;
  /** Stock de clôture manquant → le calcul est indicatif. */
  missingClosingStock: boolean;
}

/**
 * Calcule la quantité à produire pour UN produit.
 *
 * Cas limites couverts :
 * - stock de clôture ≥ requis → 0 (jamais de valeur négative) ;
 * - seuil absent → 0, `missingThreshold = true` ;
 * - stock de clôture absent → considéré comme 0 (il faut bien produire le requis),
 *   mais `missingClosingStock = true` pour l'affichage « données partielles » ;
 * - `wasteFactor` appliqué APRÈS le max(0, …), jamais sur un besoin nul.
 */
export function computeProductionLine(input: ProductionLineInput): ProductionLineResult {
  const { product } = input;

  const missingThreshold = input.requiredPieces === null || input.requiredPieces === undefined;
  const missingClosingStock = input.closingStock === null || input.closingStock === undefined;

  const requiredPieces = missingThreshold ? 0 : Math.max(0, input.requiredPieces!);
  const closingStock = missingClosingStock ? 0 : input.closingStock!;

  const rawQtyToProduce = Math.max(0, round9(requiredPieces - closingStock));

  const wasteFactor = Number.isFinite(product.wasteFactor) ? Math.max(0, product.wasteFactor) : 0;
  const withWaste = rawQtyToProduce * (1 + wasteFactor);

  const qtyToProduce = roundQuantity(withWaste, {
    step: product.roundingStep,
    mode: product.roundingMode,
  });

  return {
    productId: product.id,
    productCode: product.code,
    requiredPieces,
    closingStock,
    rawQtyToProduce,
    qtyToProduce,
    missingThreshold,
    missingClosingStock,
  };
}

export interface ProductionPlanInput {
  /** Date du comptage du soir (`YYYY-MM-DD`). Le plan vise le lendemain. */
  today: string;
  /** Produits ACTIFS uniquement — le filtrage se fait en amont. */
  products: Product[];
  /** Stocks de clôture 22:00, indexés par `productId`. */
  closingStocks: Record<string, number | null>;
  /** Matrice des seuils (toutes lignes ; le filtrage par jour est fait ici). */
  parMatrix: ParMatrixEntry[];
  /** Poste concerné — sert à choisir un seuil spécifique poste s'il existe. */
  workstationId: string;
}

export interface ProductionPlanResult {
  /** Date visée par le plan = lendemain de `today`. */
  forDate: string;
  /** Jour de la semaine du lendemain (gère dimanche → lundi). */
  forDayOfWeek: DayOfWeek;
  workstationId: string;
  lines: ProductionLineResult[];
  /** Produits actifs sans seuil défini pour ce jour → avertissement admin (§5.2). */
  warningsMissingThreshold: string[];
}

/**
 * Calcule le plan de production complet pour le lendemain.
 *
 * Sélection du seuil : un seuil défini pour le poste courant prime sur le seuil
 * global (`workstationId = null`). Cela répond à la question ouverte §10
 * « seuils par poste ou globaux ? » sans imposer de choix : tant qu'aucun seuil
 * par poste n'est saisi, le comportement est purement global.
 */
export function computeProductionPlan(input: ProductionPlanInput): ProductionPlanResult {
  const forDate = nextDay(input.today);
  const forDayOfWeek = dayOfWeekOf(forDate);

  const lines = input.products.map((product) => {
    const requiredPieces = resolveRequiredPieces(
      input.parMatrix,
      product.id,
      forDayOfWeek,
      input.workstationId,
    );
    return computeProductionLine({
      product,
      closingStock: input.closingStocks[product.id] ?? null,
      requiredPieces,
    });
  });

  return {
    forDate,
    forDayOfWeek,
    workstationId: input.workstationId,
    lines,
    warningsMissingThreshold: lines.filter((l) => l.missingThreshold).map((l) => l.productId),
  };
}

/**
 * Retrouve le seuil applicable : priorité au seuil du poste, repli sur le seuil global.
 * Renvoie null si aucun seuil n'est défini (≠ seuil défini à 0, qui est un choix admin).
 */
export function resolveRequiredPieces(
  parMatrix: ParMatrixEntry[],
  productId: string,
  dayOfWeek: DayOfWeek,
  workstationId: string | null,
): number | null {
  const candidates = parMatrix.filter(
    (entry) => entry.productId === productId && entry.dayOfWeek === dayOfWeek,
  );
  if (candidates.length === 0) return null;

  const perWorkstation = workstationId
    ? candidates.find((entry) => entry.workstationId === workstationId)
    : undefined;
  if (perWorkstation) return perWorkstation.requiredPieces;

  const global = candidates.find((entry) => entry.workstationId === null);
  return global ? global.requiredPieces : null;
}

function round9(value: number): number {
  return Math.round(value * 1e9) / 1e9;
}
