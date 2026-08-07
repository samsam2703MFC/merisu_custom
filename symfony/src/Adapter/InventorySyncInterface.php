<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\OutboxEntry;

/**
 * ADAPTATEUR SORTANT — remontée des comptages vers TF Buddy.
 *
 * Le seul adaptateur de ce module qui ÉCRIT chez l'hôte. Tous les autres
 * lisent. C'est aussi le seul dont la défaillance ne doit jamais se voir au
 * comptoir : la validation d'un comptage écrit dans la file d'envoi et rend
 * la main, et cet adaptateur est appelé plus tard, hors requête.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TODO INTÉGRATION — en attente du contrat des endpoints
 *
 * Les chemins existent et répondent :
 *
 *   PATCH /api/v1/shops/{shopId}/products/{productId}/inventory
 *   POST  /api/v1/shops/{shopId}/materials/stocktakings
 *
 * mais la spécification ne les décrit pas — ce sont des routes relevées
 * automatiquement, qui annoncent elles-mêmes que « request, authorization and
 * response contracts are not inferred by coverage generation ». On connaît
 * donc le verbe, le chemin et les paramètres d'URL, PAS la forme du corps.
 *
 * Pour écrire l'implémentation il manque exactement trois choses :
 *
 *   1. le corps attendu par chacun des deux appels — une requête réelle
 *      suffit, ou une réponse d'erreur 422 qui nomme les champs manquants ;
 *   2. l'identifiant de boutique côté TF Buddy — `ShopRankingServiceInterface`
 *      expose déjà `currentShopId()`, à confirmer qu'il s'agit du même ;
 *   3. un compte de service et son jeton — l'authentification est un JWT
 *      « bearerAuth », obtenu par `POST /api/v1/employees/authenticate`.
 *
 * Le pont sur les identifiants produit est en place : `Product::recipeRef`
 * porte l'identifiant du produit côté hôte, et se saisit dans Admin ▸ Produits.
 * Une ligne sans `recipeRef` n'est pas mise en file — voir `InventorySync`.
 *
 * Déclarez ensuite votre implémentation à la place de `NullInventorySync`
 * dans `config/services.yaml`.
 * ═══════════════════════════════════════════════════════════════════════════
 */
interface InventorySyncInterface
{
    /**
     * Envoie une entrée de la file.
     *
     * @throws SyncUnavailable      hôte injoignable ou non configuré — la ligne
     *                              reste en file et sera réessayée
     * @throws \RuntimeException    refus définitif de l'hôte — la ligne est
     *                              marquée en échec sans nouvel essai
     */
    public function send(OutboxEntry $entry): void;

    /**
     * L'adaptateur est-il en état d'envoyer ?
     *
     * Interrogé avant de vider la file : tant qu'aucune implémentation réelle
     * n'est branchée, rien n'est tenté et AUCUNE tentative n'est consommée.
     * Sans ce garde-fou, les huit essais d'une ligne se seraient épuisés
     * contre un adaptateur muet, et le comptage aurait été marqué « en échec »
     * avant même que l'intégration existe.
     */
    public function isConfigured(): bool;
}
