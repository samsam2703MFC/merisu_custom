/**
 * §5.1 — Écart net du jour.
 *
 *   Consommation nette = Stock initial (08:00) + Production entrée dans la journée − Stock final (22:00)
 *
 * Interprétation métier : ce qui a été écoulé (ventes + pertes) entre l'ouverture
 * et la clôture. Si la production intra-journée n'est pas suivie, la formule
 * retombe naturellement sur `Stock initial − Stock final` (production = 0).
 */

import type { DailyNet } from './types.js';

export interface NetVarianceInput {
  date: string;
  productId: string;
  workstationId: string;
  /** Comptage 08:00. null si non saisi. */
  openingStock: number | null;
  /** Comptage 22:00. null si non saisi. */
  closingStock: number | null;
  /** Production entrée en stock dans la journée. Absent/null ⇒ 0 (non suivie). */
  producedQty?: number | null;
}

/**
 * Calcule l'écart net d'un produit sur une journée.
 *
 * Tant qu'un des deux comptages manque, le résultat est `null` et `partial` vaut
 * true : on n'affiche pas de conclusion fausse sur des données incomplètes (§6).
 */
export function computeDailyNet(input: NetVarianceInput): DailyNet {
  const producedQty = input.producedQty ?? 0;
  const hasOpening = input.openingStock !== null && input.openingStock !== undefined;
  const hasClosing = input.closingStock !== null && input.closingStock !== undefined;
  const partial = !hasOpening || !hasClosing;

  const netConsumption = partial
    ? null
    : round9(input.openingStock! + producedQty - input.closingStock!);

  return {
    date: input.date,
    productId: input.productId,
    workstationId: input.workstationId,
    openingStock: hasOpening ? input.openingStock! : null,
    closingStock: hasClosing ? input.closingStock! : null,
    producedQty,
    netConsumption,
    partial,
  };
}

/** Calcule l'écart net pour une liste de couples produit/jour. */
export function computeDailyNets(inputs: NetVarianceInput[]): DailyNet[] {
  return inputs.map(computeDailyNet);
}

/**
 * Somme des consommations nettes d'une série, en ignorant les jours incomplets.
 * Renvoie aussi le nombre de jours écartés, pour l'affichage « données partielles ».
 */
export function sumNetConsumption(nets: DailyNet[]): { total: number; skipped: number; partial: boolean } {
  let total = 0;
  let skipped = 0;
  for (const net of nets) {
    if (net.netConsumption === null) {
      skipped += 1;
      continue;
    }
    total += net.netConsumption;
  }
  return { total: round9(total), skipped, partial: skipped > 0 };
}

function round9(value: number): number {
  return Math.round(value * 1e9) / 1e9;
}
