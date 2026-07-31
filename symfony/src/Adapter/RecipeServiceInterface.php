<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * ADAPTATEUR — Module « Product Recipe Technique » EXISTANT (recettes/nomenclatures).
 *
 * ⚠️ LECTURE SEULE. Ce module consomme les recettes pour calculer le delta
 * technique (§6) ; il ne les crée ni ne les modifie jamais.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TODO INTÉGRATION — à faire au branchement sur le vrai module :
 *   1. Écrire une classe implémentant cette interface, qui lit les recettes du
 *      module existant (service injecté, API, ou vue SQL en lecture seule).
 *   2. FAIRE LE PONT SUR LES IDENTIFIANTS : chaque fiche produit porte un champ
 *      `recipeRef`, prévu pour stocker l'identifiant du produit côté module
 *      Recette. Il se renseigne dans Admin ▸ Produits.
 *   3. Vérifier l'unité des quantités : le delta technique suppose qu'elles sont
 *      exprimées dans l'unité de la matière (g, ml, pièce).
 *   4. Déclarer votre implémentation à la place de LocalRecipeService dans
 *      `config/services.yaml`.
 * ═══════════════════════════════════════════════════════════════════════════
 */
interface RecipeServiceInterface
{
    /**
     * Recettes des produits demandés, en une passe (évite le N+1 sur les rapports).
     *
     * @param list<string> $productIds
     *
     * @return array<string, array<string, float>> [productId][materialId] => qtyPerUnit
     */
    public function recipes(array $productIds): array;

    /** @return list<Material> */
    public function materials(): array;

    public function material(string $id): ?Material;
}
