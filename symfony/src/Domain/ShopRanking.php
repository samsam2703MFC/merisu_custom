<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

use Merisu\Inventory\Adapter\ShopPerformance;

/**
 * Établit un classement de boutiques.
 *
 * Séparé de l'adaptateur à dessein : la source des chiffres changera — caisse,
 * entrepôt de données, export — mais les règles de classement, elles, doivent
 * rester vérifiables sans jamais brancher quoi que ce soit.
 */
final class ShopRanking
{
    /**
     * Classe les boutiques, de la meilleure à la moins bonne.
     *
     * Deux boutiques à égalité partagent le MÊME rang, et le rang suivant saute
     * d'autant — deux premières ex æquo sont suivies d'une troisième, pas d'une
     * seconde. C'est la convention sportive, et celle que le lecteur attend
     * quand il compare deux tableaux.
     *
     * @param list<ShopPerformance> $shops
     *
     * @return list<array{rank: int, shop: ShopPerformance, value: float, isCurrent: bool}>
     */
    public static function build(
        array $shops,
        RankingMetric $metric,
        ?string $currentShopId = null,
        ?string $country = null,
    ): array {
        if ($country !== null) {
            $shops = array_values(array_filter(
                $shops,
                static fn (ShopPerformance $s): bool => strtoupper($s->country) === strtoupper($country),
            ));
        }

        // Le chiffre d'affaires ne se compare qu'à devise égale : sans taux de
        // change fiable, mettre 1 000 PLN et 1 000 EUR sur la même échelle
        // fabriquerait un classement faux et personne ne le verrait.
        if ($metric->isMonetary()) {
            $shops = self::sameCurrencyAs($shops, $currentShopId);
        }

        usort($shops, static function (ShopPerformance $a, ShopPerformance $b) use ($metric): int {
            $ecart = $metric->valueOf($b) <=> $metric->valueOf($a);

            // À égalité, l'ordre alphabétique : sans cela le classement
            // changerait d'un affichage à l'autre, et paraîtrait truqué.
            return $ecart !== 0 ? $ecart : strcasecmp($a->name, $b->name);
        });

        $lignes = [];
        $rang = 0;
        $precedente = null;

        foreach ($shops as $index => $shop) {
            $valeur = $metric->valueOf($shop);

            if ($precedente === null || $valeur !== $precedente) {
                $rang = $index + 1;
                $precedente = $valeur;
            }

            $lignes[] = [
                'rank' => $rang,
                'shop' => $shop,
                'value' => $valeur,
                'isCurrent' => $currentShopId !== null && $shop->id === $currentShopId,
            ];
        }

        return $lignes;
    }

    /**
     * Position d'une boutique dans un classement déjà construit.
     *
     * @param list<array{rank: int, shop: ShopPerformance, value: float, isCurrent: bool}> $ranking
     *
     * @return array{rank: int, total: int}|null
     */
    public static function positionOf(array $ranking, ?string $shopId): ?array
    {
        if ($shopId === null) {
            return null;
        }

        foreach ($ranking as $ligne) {
            if ($ligne['shop']->id === $shopId) {
                return ['rank' => $ligne['rank'], 'total' => \count($ranking)];
            }
        }

        return null;
    }

    /** Pays représentés, dédoublonnés et triés — alimente le sélecteur. */
    public static function countries(array $shops): array
    {
        $pays = array_map(
            static fn (ShopPerformance $s): string => strtoupper($s->country),
            $shops,
        );

        $pays = array_values(array_unique(array_filter($pays)));
        sort($pays);

        return $pays;
    }

    /**
     * Restreint à la devise de la boutique courante.
     *
     * Sans boutique courante connue, on retient la devise la plus représentée :
     * mieux vaut un classement partiel mais juste qu'un palmarès qui additionne
     * des monnaies.
     *
     * @param list<ShopPerformance> $shops
     *
     * @return list<ShopPerformance>
     */
    private static function sameCurrencyAs(array $shops, ?string $currentShopId): array
    {
        if ($shops === []) {
            return [];
        }

        $devise = null;

        foreach ($shops as $shop) {
            if ($shop->id === $currentShopId) {
                $devise = $shop->currency;
                break;
            }
        }

        if ($devise === null) {
            $comptes = [];
            foreach ($shops as $shop) {
                $comptes[$shop->currency] = ($comptes[$shop->currency] ?? 0) + 1;
            }
            arsort($comptes);
            $devise = array_key_first($comptes);
        }

        return array_values(array_filter(
            $shops,
            static fn (ShopPerformance $s): bool => $s->currency === $devise,
        ));
    }
}
