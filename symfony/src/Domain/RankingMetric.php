<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

use Merisu\Inventory\Adapter\ShopPerformance;

/**
 * Les trois classements du réseau.
 *
 * Trois et pas un seul, parce qu'ils ne disent pas la même chose : une
 * boutique de gare fait beaucoup de clients et peu de panier, une boutique de
 * centre-ville l'inverse. Les mettre côte à côte évite qu'un unique palmarès
 * ne désigne toujours les mêmes gagnants.
 */
enum RankingMetric: string
{
    case Revenue = 'REVENUE';
    case Customers = 'CUSTOMERS';
    case TiramisuSold = 'TIRAMISU';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Revenue, self::Customers, self::TiramisuSold];
    }

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtoupper(trim($value)));
    }

    /** Valeur à classer, extraite d'une performance de boutique. */
    public function valueOf(ShopPerformance $shop): float
    {
        return match ($this) {
            self::Revenue => $shop->revenue,
            self::Customers => (float) $shop->customers,
            self::TiramisuSold => (float) $shop->tiramisuSold,
        };
    }

    /**
     * Un chiffre d'affaires se compare en devise, pas les deux autres.
     *
     * Sans taux de change fiable, comparer 1 000 PLN et 1 000 EUR serait faux ;
     * des clients et des tiramisu se comptent, eux, à l'identique partout.
     */
    public function isMonetary(): bool
    {
        return $this === self::Revenue;
    }

    public function labelKey(): string
    {
        return 'network.metrics.' . $this->value;
    }
}
