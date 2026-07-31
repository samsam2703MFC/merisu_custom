/**
 * ADAPTATEUR — Module « Product Recipe Technique » EXISTANT (recettes / nomenclatures).
 *
 * ⚠️ LECTURE SEULE. Le module Inventaire & Production consomme les recettes pour
 * calculer le delta technique (§6) ; il ne les crée ni ne les modifie jamais.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TODO INTÉGRATION — à faire au branchement sur le vrai module :
 *   1. Écrire une classe implémentant `RecipeService` qui lit les recettes du
 *      module existant (API, service partagé, ou vue SQL en lecture seule).
 *   2. Faire correspondre les identifiants : la fiche produit d'Inventaire porte
 *      un champ `recipeRef` prévu pour stocker l'identifiant côté module recette
 *      (voir `Product.recipeRef`). C'est cette clé qui sert de pont.
 *   3. Vérifier l'unité des `qtyPerUnit` : le delta technique suppose que la
 *      quantité est exprimée dans l'unité de la matière (g, ml, pièce).
 *   4. Remplacer `LocalRecipeService` dans `createRecipeService()`.
 * ═══════════════════════════════════════════════════════════════════════════
 */

import type { Material, Recipe } from '@merisu/domain';

export interface RecipeService {
  /** Recette d'un produit, ou null si le produit n'en a pas encore. */
  getRecipe(productId: string): Promise<Recipe | null>;
  /** Recettes de plusieurs produits, en une passe (évite le N+1 sur les rapports). */
  getRecipes(productIds: string[]): Promise<Recipe[]>;
  listMaterials(): Promise<Material[]>;
  getMaterial(id: string): Promise<Material | null>;
}

/**
 * Implémentation de repli : recettes stockées localement (seed placeholder).
 * Les recettes réelles arriveront du module existant — rien n'est codé en dur ici,
 * le contenu vient du seed, remplaçable par l'admin ou par l'import.
 */
export class LocalRecipeService implements RecipeService {
  constructor(
    private readonly recipes: Recipe[],
    private readonly materials: Material[],
  ) {}

  async getRecipe(productId: string): Promise<Recipe | null> {
    return this.recipes.find((r) => r.productId === productId) ?? null;
  }

  async getRecipes(productIds: string[]): Promise<Recipe[]> {
    const wanted = new Set(productIds);
    return this.recipes.filter((r) => wanted.has(r.productId));
  }

  async listMaterials(): Promise<Material[]> {
    return this.materials;
  }

  async getMaterial(id: string): Promise<Material | null> {
    return this.materials.find((m) => m.id === id) ?? null;
  }
}

/**
 * Point de bascule unique vers le vrai module.
 *
 * TODO INTÉGRATION : par exemple
 *
 *   if (process.env.RECIPE_MODULE_URL) {
 *     return new HttpRecipeService(process.env.RECIPE_MODULE_URL);
 *   }
 */
export function createRecipeService(fallback: () => RecipeService): RecipeService {
  return fallback();
}
