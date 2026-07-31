/**
 * §6 — Delta technique : recettes ↔ consommation réelle de matières.
 *
 *   Consommation théorique(matière) = Σ ( pièces produites(produit) × qty_par_unité(produit, matière) )
 *   Consommation réelle(matière)    = variation réelle de stock matière sur la période
 *   Delta                           = réel − théorique
 *   Delta %                         = Delta / théorique
 *
 * Ces calculs sont INDICATIFS tant que les données ne sont pas complètes :
 * chaque résultat porte un drapeau `partial` que l'IHM doit afficher.
 */

import type { MaterialDelta, Recipe } from './types.js';

export interface ProducedQtyByProduct {
  productId: string;
  /** Pièces réellement produites sur la période (source : plan réalisé ou écart net). */
  producedQty: number;
}

/**
 * Consommation théorique par matière, à partir des recettes et des quantités produites.
 * Les produits sans recette connue sont remontés dans `productsWithoutRecipe`.
 */
export function computeTheoreticalConsumption(params: {
  produced: ProducedQtyByProduct[];
  recipes: Recipe[];
}): {
  byMaterial: Record<string, number>;
  productsWithoutRecipe: string[];
} {
  const recipeByProduct = new Map(params.recipes.map((r) => [r.productId, r]));
  const byMaterial: Record<string, number> = {};
  const productsWithoutRecipe: string[] = [];

  for (const entry of params.produced) {
    const recipe = recipeByProduct.get(entry.productId);
    if (!recipe || recipe.lines.length === 0) {
      // Une recette absente n'invalide pas le rapport : elle le rend partiel.
      if (entry.producedQty !== 0) productsWithoutRecipe.push(entry.productId);
      continue;
    }
    for (const line of recipe.lines) {
      const current = byMaterial[line.materialId] ?? 0;
      byMaterial[line.materialId] = round9(current + entry.producedQty * line.qtyPerUnit);
    }
  }

  return { byMaterial, productsWithoutRecipe };
}

export interface MaterialDeltaInput {
  /** Consommation théorique par matière (sortie de `computeTheoreticalConsumption`). */
  theoreticalByMaterial: Record<string, number>;
  /** Consommation réelle par matière (variation de stock). Absente = inconnue. */
  realByMaterial: Record<string, number | null | undefined>;
  /** Tolérance paramétrée en admin, ex. 0.05 pour 5 %. */
  tolerance: number;
}

/**
 * Calcule le delta par matière et signale les dépassements de tolérance.
 *
 * Cas limites :
 * - théorique = 0 et réel > 0 → delta connu, `deltaPct` null (division par zéro),
 *   marqué hors tolérance car consommer sans production théorique est une dérive ;
 * - réel inconnu → delta et pourcentage null, `partial = true`, jamais hors tolérance.
 */
export function computeMaterialDeltas(input: MaterialDeltaInput): MaterialDelta[] {
  const tolerance = Number.isFinite(input.tolerance) ? Math.abs(input.tolerance) : 0;

  const materialIds = new Set<string>([
    ...Object.keys(input.theoreticalByMaterial),
    ...Object.keys(input.realByMaterial),
  ]);

  const result: MaterialDelta[] = [];

  for (const materialId of [...materialIds].sort()) {
    const theoreticalQty = round9(input.theoreticalByMaterial[materialId] ?? 0);
    const rawReal = input.realByMaterial[materialId];
    const hasReal = rawReal !== null && rawReal !== undefined && Number.isFinite(rawReal);
    const realQty = hasReal ? round9(rawReal as number) : null;

    if (!hasReal) {
      result.push({
        materialId,
        theoreticalQty,
        realQty: null,
        delta: null,
        deltaPct: null,
        outOfTolerance: false,
        partial: true,
      });
      continue;
    }

    const delta = round9(realQty! - theoreticalQty);
    const deltaPct = theoreticalQty === 0 ? null : round9(delta / theoreticalQty);

    const outOfTolerance =
      deltaPct === null ? delta !== 0 : Math.abs(deltaPct) > tolerance;

    result.push({
      materialId,
      theoreticalQty,
      realQty,
      delta,
      deltaPct,
      outOfTolerance,
      partial: false,
    });
  }

  return result;
}

/** Synthèse d'un rapport de delta, pour l'entête de l'écran admin. */
export function summarizeDeltas(deltas: MaterialDelta[]): {
  materials: number;
  outOfTolerance: number;
  partial: boolean;
} {
  return {
    materials: deltas.length,
    outOfTolerance: deltas.filter((d) => d.outOfTolerance).length,
    partial: deltas.some((d) => d.partial),
  };
}

function round9(value: number): number {
  return Math.round(value * 1e9) / 1e9;
}
