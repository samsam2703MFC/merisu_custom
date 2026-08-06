<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

use Merisu\Inventory\Adapter\ShopPerformance;

/**
 * Chiffres de vente de la boutique du poste, prêts à afficher.
 *
 * ⚠️ Rien ici n'est calculé à partir des stocks. Le chiffre d'affaires, les
 * clients et les tiramisu vendus viennent de la CAISSE
 * (`ShopRankingServiceInterface`) ; cette classe ne fait que les mettre en
 * forme et les situer dans le réseau.
 *
 * Séparée du contrôleur pour que les cas tordus — réseau vide, boutique
 * inconnue, zéro vente, une seule boutique — soient vérifiables sans base ni
 * requête HTTP.
 */
final readonly class SalesSummary
{
    private function __construct(
        /** Boutique du poste. Null quand la caisse ne la connaît pas. */
        public ?ShopPerformance $shop,
        /** Rang sur les tiramisu vendus, dans le monde. Null sans boutique. */
        public ?int $rank,
        public int $shopCount,
        /** Tiramisu vendus par l'ensemble du réseau sur la période. */
        public int $networkTiramisu,
    ) {
    }

    /** @param list<ShopPerformance> $performances */
    public static function of(array $performances, ?string $currentShopId): self
    {
        $shop = null;
        $total = 0;

        foreach ($performances as $p) {
            $total += $p->tiramisuSold;

            if ($currentShopId !== null && $p->id === $currentShopId) {
                $shop = $p;
            }
        }

        $rang = null;
        if ($shop !== null) {
            foreach (ShopRanking::build($performances, RankingMetric::TiramisuSold, $currentShopId) as $ligne) {
                if ($ligne['isCurrent']) {
                    $rang = $ligne['rank'];
                    break;
                }
            }
        }

        return new self($shop, $rang, \count($performances), $total);
    }

    /**
     * Part de la boutique dans les tiramisu du réseau, en pourcentage.
     *
     * Null plutôt que 0 quand le réseau n'a rien vendu : « 0 % d'un total nul »
     * n'est pas une performance médiocre, c'est une absence de mesure, et
     * l'écran doit pouvoir faire la différence.
     */
    public function shareOfNetwork(): ?float
    {
        if ($this->shop === null || $this->networkTiramisu <= 0) {
            return null;
        }

        return $this->shop->tiramisuSold / $this->networkTiramisu * 100;
    }

    /**
     * Longueur de chaque barre, en pourcentage de la plus longue.
     *
     * L'échelle part de la plus forte valeur et non du total : sur huit
     * boutiques, des barres exprimées en part du total tiennent toutes dans le
     * premier huitième de la largeur et ne se comparent plus.
     *
     * @param list<array{rank: int, shop: ShopPerformance, value: float, isCurrent: bool}> $ranking
     *
     * @return list<array{rank: int, shop: ShopPerformance, value: float, isCurrent: bool, percent: float}>
     */
    public static function bars(array $ranking): array
    {
        $max = 0.0;
        foreach ($ranking as $ligne) {
            $max = max($max, $ligne['value']);
        }

        return array_map(
            static function (array $ligne) use ($max): array {
                // Toutes les barres à zéro quand le maximum l'est : une barre
                // pleine partout ferait croire à un réseau à l'équilibre alors
                // qu'il n'a rien vendu.
                $ligne['percent'] = $max > 0 ? max(0.0, $ligne['value']) / $max * 100 : 0.0;

                return $ligne;
            },
            $ranking,
        );
    }
}
