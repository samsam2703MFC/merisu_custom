/**
 * Arrondi paramétrable des quantités à produire (§5.2).
 *
 * Le pas et le mode viennent de la fiche produit (admin) : on ne suppose jamais
 * « à la pièce entière », certains produits se comptent au gramme ou au ml.
 */

import type { RoundingMode } from './types.js';

/** Corrige les artefacts de virgule flottante (0.1 + 0.2 → 0.30000000000000004). */
function clean(value: number): number {
  return Math.round(value * 1e9) / 1e9;
}

export interface RoundingOptions {
  /** Pas d'arrondi (>0). 1 = à l'unité, 6 = par lot de 6, 0.5 = au demi. */
  step: number;
  mode: RoundingMode;
}

/**
 * Arrondit `value` selon le pas et le mode fournis.
 *
 * - `NONE` : valeur inchangée (nettoyée des artefacts flottants).
 * - `CEIL` : multiple supérieur — défaut métier, on ne produit jamais sous le besoin.
 * - `FLOOR` : multiple inférieur.
 * - `NEAREST` : multiple le plus proche (0.5 arrondi vers le haut).
 *
 * Un pas invalide (0, négatif, NaN) est ignoré : on retombe sur la valeur brute
 * plutôt que de renvoyer Infinity/NaN dans un plan de production.
 */
export function roundQuantity(value: number, options: RoundingOptions): number {
  const { step, mode } = options;

  if (!Number.isFinite(value)) return 0;
  if (mode === 'NONE') return clean(value);
  if (!Number.isFinite(step) || step <= 0) return clean(value);

  const ratio = clean(value / step);

  switch (mode) {
    case 'CEIL':
      return clean(Math.ceil(ratio) * step);
    case 'FLOOR':
      return clean(Math.floor(ratio) * step);
    case 'NEAREST':
      return clean(Math.round(ratio) * step);
    default:
      return clean(value);
  }
}
