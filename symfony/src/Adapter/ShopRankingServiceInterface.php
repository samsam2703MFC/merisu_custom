<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * ════════════════════════════════════════════════════════════════════════════
 * POINT D'INTÉGRATION — RÉSULTATS DES BOUTIQUES DU RÉSEAU
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Ce module compte des stocks. Il ne connaît ni les encaissements, ni les
 * tickets, ni les ventes : le chiffre d'affaires, le nombre de clients et les
 * tiramisu vendus viennent de la CAISSE, et les chiffres des AUTRES boutiques
 * viennent d'un service qui les centralise.
 *
 * Rien de tout cela n'est déduit ici, et rien ne doit l'être : un classement
 * calculé sur des stocks donnerait un résultat faux — un site qui produit
 * beaucoup et jette beaucoup passerait devant celui qui vend tout.
 *
 * ── TODO INTÉGRATION ────────────────────────────────────────────────────────
 *
 * 1. Écrire une implémentation de cette interface qui interroge votre source
 *    réelle : API de la caisse, entrepôt de données, export nocturne…
 *
 * 2. La déclarer dans `config/services.yaml`, à côté des deux autres
 *    adaptateurs — c'est le SEUL fichier à modifier :
 *
 *        Merisu\Inventory\Adapter\ShopRankingServiceInterface:
 *            alias: App\Caisse\VotreServiceDeClassement
 *
 * 3. Points d'attention pour cette implémentation :
 *
 *    · `performances()` est appelée à chaque affichage de l'écran Réseau.
 *      Si la source est lente ou facturée à l'appel, mettez le résultat en
 *      cache : un classement n'a pas besoin d'être à la seconde.
 *
 *    · Les montants doivent être dans la devise de chaque boutique, avec son
 *      code ISO. Ne convertissez pas : l'écran classe par devise et ne compare
 *      jamais des montants de devises différentes, faute de taux fiable.
 *
 *    · Une boutique sans chiffre sur la période doit être OMISE plutôt que
 *      renvoyée à zéro — un zéro la placerait dernière alors qu'elle était
 *      peut-être fermée.
 *
 * ════════════════════════════════════════════════════════════════════════════
 */
interface ShopRankingServiceInterface
{
    /**
     * Résultats de toutes les boutiques du réseau sur la période.
     *
     * @param string $from Date métier de début, incluse (AAAA-MM-JJ)
     * @param string $to   Date métier de fin, incluse (AAAA-MM-JJ)
     *
     * @return list<ShopPerformance>
     */
    public function performances(string $from, string $to): array;

    /**
     * Boutique du poste courant, pour la situer dans le classement.
     *
     * `null` si l'information n'est pas connue : l'écran affiche alors le
     * classement sans mettre personne en avant, ce qui reste utile.
     */
    public function currentShopId(): ?string;
}
