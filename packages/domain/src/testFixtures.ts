/**
 * Fabriques d'objets pour les tests.
 * Ce ne sont PAS des données métier : aucun produit réel n'est codé ici.
 */

import type { DayOfWeek, ParMatrixEntry, Product, Recipe } from './types.js';

export function makeProduct(overrides: Partial<Product> = {}): Product {
  return {
    id: overrides.id ?? 'p1',
    code: overrides.code ?? 'PRODUIT_1',
    name: overrides.name ?? { fr: 'Slot 1' },
    unit: overrides.unit ?? 'pcs',
    active: overrides.active ?? true,
    wasteFactor: overrides.wasteFactor ?? 0,
    roundingStep: overrides.roundingStep ?? 1,
    roundingMode: overrides.roundingMode ?? 'CEIL',
    recipeRef: overrides.recipeRef ?? null,
    sortOrder: overrides.sortOrder ?? 1,
  };
}

export function makeParEntry(
  productId: string,
  dayOfWeek: DayOfWeek,
  requiredPieces: number,
  workstationId: string | null = null,
): ParMatrixEntry {
  return {
    id: `${productId}-${dayOfWeek}-${workstationId ?? 'global'}`,
    productId,
    dayOfWeek,
    requiredPieces,
    workstationId,
  };
}

export function makeRecipe(productId: string, lines: Array<[string, number]>): Recipe {
  return {
    productId,
    lines: lines.map(([materialId, qtyPerUnit]) => ({ materialId, qtyPerUnit })),
  };
}
