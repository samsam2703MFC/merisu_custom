/**
 * ═══════════════════════════════════════════════════════════════════════════
 * DONNÉES DE DÉMONSTRATION — PLACEHOLDERS À REMPLACER
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * RIEN ici n'est une donnée métier validée. Ce fichier existe uniquement pour
 * que l'application soit démontrable avant que l'admin ne saisisse le réel.
 *
 * À remplacer (cf. §10 du cahier des charges) :
 *   • libellés et unités définitifs des 8 produits   → écran Admin ▸ Produits
 *   • matrice des seuils réelle                       → écran Admin ▸ Seuils
 *   • recettes réelles                                → module « Recette technique »
 *   • consultants et postes                           → module « Consultant/Stanowisko »
 *
 * Les codes PRODUIT_1..8 sont des identifiants STABLES : ils ne changent pas
 * quand l'admin renomme un produit, et ne sont jamais affichés à l'utilisateur.
 */

import type { DayOfWeek, Material, Product, Recipe, Workstation } from '@merisu/domain';

import type { LocalConsultantRecord } from './adapters/consultantService.js';

/** Libellés provisoires cités dans le cahier des charges — à écraser en admin. */
const PLACEHOLDER_LABELS = [
  'Cream 1',
  'Cream 2',
  'Cream 3',
  'Coffee',
  'Biscuit',
  'Coffee 2',
  'Slot 7',
  'Slot 8',
];

/**
 * Les 8 emplacements produits.
 *
 * Ils sont créés ACTIFS pour que la démonstration fonctionne immédiatement ;
 * l'admin désactive ceux qui ne servent pas. Le même libellé provisoire est posé
 * dans les 4 langues : c'est un marqueur visible de « donnée à remplir », pas une
 * traduction. L'admin saisira le vrai nom par langue.
 */
export const DEMO_PRODUCTS: Product[] = PLACEHOLDER_LABELS.map((label, index) => ({
  id: `product-${index + 1}`,
  code: `PRODUIT_${index + 1}`,
  name: { fr: label, pl: label, it: label, es: label },
  unit: 'pcs',
  active: true,
  wasteFactor: 0,
  roundingStep: 1,
  roundingMode: 'CEIL',
  recipeRef: null,
  sortOrder: index + 1,
}));

/**
 * Matrice de seuils FICTIVE : même valeur en semaine, renforcée le week-end.
 * Purement illustrative — à remplacer intégralement par les vrais « par levels ».
 */
const WEEKDAY_PLACEHOLDER = 20;
const WEEKEND_PLACEHOLDER = 30;

export const DEMO_PAR_MATRIX: Array<{
  productId: string;
  dayOfWeek: DayOfWeek;
  requiredPieces: number;
  workstationId: string | null;
}> = DEMO_PRODUCTS.flatMap((product) =>
  (['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'] as DayOfWeek[]).map((dayOfWeek) => ({
    productId: product.id,
    dayOfWeek,
    requiredPieces: dayOfWeek === 'SAT' || dayOfWeek === 'SUN'
      ? WEEKEND_PLACEHOLDER
      : WEEKDAY_PLACEHOLDER,
    workstationId: null,
  })),
);

/** Postes de démonstration — remplacés par ceux du module Consultant existant. */
export const DEMO_WORKSTATIONS: Workstation[] = [
  { id: 'ws-1', name: 'Stanowisko 1', active: true },
  { id: 'ws-2', name: 'Stanowisko 2', active: true },
];

/**
 * Comptes de démonstration.
 * ⚠️ Codes PIN triviaux : ils n'existent que tant que le module Consultant n'est
 * pas branché. Ne jamais déployer en production avec ces comptes.
 */
export const DEMO_CONSULTANTS: LocalConsultantRecord[] = [
  {
    id: 'admin',
    displayName: 'Admin MERISU',
    role: 'ADMIN',
    defaultWorkstationId: 'ws-1',
    active: true,
    secret: '0000',
  },
  {
    id: 'consultant1',
    displayName: 'Consultant 1',
    role: 'CONSULTANT',
    defaultWorkstationId: 'ws-1',
    active: true,
    secret: '1111',
  },
  {
    id: 'consultant2',
    displayName: 'Consultant 2',
    role: 'CONSULTANT',
    defaultWorkstationId: 'ws-2',
    active: true,
    secret: '2222',
  },
];

/** Matières fictives — les vraies viennent du module Recette. */
export const DEMO_MATERIALS: Material[] = [
  { id: 'mat-milk', name: { fr: 'Lait', pl: 'Mleko', it: 'Latte', es: 'Leche' }, unit: 'l', currentStock: null },
  { id: 'mat-sugar', name: { fr: 'Sucre', pl: 'Cukier', it: 'Zucchero', es: 'Azúcar' }, unit: 'kg', currentStock: null },
  { id: 'mat-coffee', name: { fr: 'Café', pl: 'Kawa', it: 'Caffè', es: 'Café' }, unit: 'kg', currentStock: null },
  { id: 'mat-flour', name: { fr: 'Farine', pl: 'Mąka', it: 'Farina', es: 'Harina' }, unit: 'kg', currentStock: null },
];

/**
 * Recettes fictives — LECTURE SEULE dans ce module.
 * Elles seront fournies par le module « Product Recipe Technique ».
 */
export const DEMO_RECIPES: Recipe[] = [
  { productId: 'product-1', lines: [{ materialId: 'mat-milk', qtyPerUnit: 0.2 }, { materialId: 'mat-sugar', qtyPerUnit: 0.05 }] },
  { productId: 'product-2', lines: [{ materialId: 'mat-milk', qtyPerUnit: 0.25 }, { materialId: 'mat-sugar', qtyPerUnit: 0.04 }] },
  { productId: 'product-3', lines: [{ materialId: 'mat-milk', qtyPerUnit: 0.18 }, { materialId: 'mat-sugar', qtyPerUnit: 0.06 }] },
  { productId: 'product-4', lines: [{ materialId: 'mat-coffee', qtyPerUnit: 0.008 }, { materialId: 'mat-milk', qtyPerUnit: 0.1 }] },
  { productId: 'product-5', lines: [{ materialId: 'mat-flour', qtyPerUnit: 0.03 }, { materialId: 'mat-sugar', qtyPerUnit: 0.01 }] },
  { productId: 'product-6', lines: [{ materialId: 'mat-coffee', qtyPerUnit: 0.01 }] },
];
