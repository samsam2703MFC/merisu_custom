/**
 * §5.3 — Règles de validation du comptage du soir.
 *
 * Le soir ne peut être validé que si TOUS les produits actifs ont un stock saisi
 * (et une photo si l'admin l'exige). Une fois validé : saisies verrouillées,
 * plan de production figé. Déverrouillage : ADMIN uniquement, avec trace d'audit.
 */

import type { GeneralSettings, Product } from './types.js';

export type ValidationIssueCode =
  | 'MISSING_QTY'
  | 'NEGATIVE_QTY'
  | 'MISSING_PHOTO'
  | 'MISSING_GLOBAL_PHOTO'
  | 'ALREADY_VALIDATED';

export interface ValidationIssue {
  code: ValidationIssueCode;
  /** Produit concerné ; absent pour les problèmes globaux (photo globale). */
  productId?: string;
}

export interface EveningValidationInput {
  /** Produits actifs attendus au comptage. */
  activeProducts: Product[];
  /** Quantités saisies, indexées par `productId`. undefined/null = non saisi. */
  quantities: Record<string, number | null | undefined>;
  /** Nombre de photos jointes par produit. */
  photoCountByProduct: Record<string, number>;
  /** Nombre de photos globales (non rattachées à un produit précis). */
  globalPhotoCount: number;
  settings: Pick<GeneralSettings, 'photoRequired' | 'photoPerProduct'>;
  /** Le comptage a-t-il déjà été validé ? (verrouillage) */
  alreadyValidated: boolean;
}

export interface ValidationResult {
  valid: boolean;
  issues: ValidationIssue[];
}

/**
 * Vérifie qu'un comptage du soir peut être validé.
 *
 * Politique photo (question ouverte §10) : pilotée par deux paramètres admin.
 * - `photoRequired = false` → aucune photo exigée ;
 * - `photoRequired && photoPerProduct` → au moins une photo par produit actif ;
 * - `photoRequired && !photoPerProduct` → au moins une photo globale
 *   (une photo rattachée à n'importe quel produit compte comme photo globale).
 */
export function validateEveningCount(input: EveningValidationInput): ValidationResult {
  const issues: ValidationIssue[] = [];

  if (input.alreadyValidated) {
    issues.push({ code: 'ALREADY_VALIDATED' });
  }

  for (const product of input.activeProducts) {
    const qty = input.quantities[product.id];

    if (qty === null || qty === undefined || Number.isNaN(qty)) {
      issues.push({ code: 'MISSING_QTY', productId: product.id });
    } else if (qty < 0) {
      issues.push({ code: 'NEGATIVE_QTY', productId: product.id });
    }

    if (input.settings.photoRequired && input.settings.photoPerProduct) {
      if ((input.photoCountByProduct[product.id] ?? 0) < 1) {
        issues.push({ code: 'MISSING_PHOTO', productId: product.id });
      }
    }
  }

  if (input.settings.photoRequired && !input.settings.photoPerProduct) {
    const attachedPhotos = Object.values(input.photoCountByProduct).reduce((a, b) => a + b, 0);
    if (input.globalPhotoCount + attachedPhotos < 1) {
      issues.push({ code: 'MISSING_GLOBAL_PHOTO' });
    }
  }

  return { valid: issues.length === 0, issues };
}

/** Une saisie verrouillée n'est modifiable que par un ADMIN (avec trace d'audit). */
export function canEditCount(params: {
  validated: boolean;
  role: 'CONSULTANT' | 'ADMIN';
}): boolean {
  if (!params.validated) return true;
  return params.role === 'ADMIN';
}
