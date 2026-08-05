<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

use Merisu\Inventory\Adapter\ShopPerformance;

/**
 * Les deux classements du réseau : clients et tiramisu vendus.
 *
 * Deux et pas un seul, parce qu'ils ne disent pas la même chose : une boutique
 * de gare fait beaucoup de clients et peu de tiramisu chacun, une boutique de
 * centre-ville l'inverse. Les mettre côte à côte évite qu'un unique palmarès
 * ne désigne toujours les mêmes gagnants.
 *
 * Le chiffre d'affaires n'y figure pas, et c'est délibéré : il se compare en
 * devise, ce qui obligeait à restreindre le classement mondial aux boutiques
 * d'une même monnaie — un « classement mondial » amputé de la moitié du
 * réseau. Des clients et des tiramisu se comptent à l'identique partout.
 */
enum RankingMetric: string
{
    case Customers = 'CUSTOMERS';
    case TiramisuSold = 'TIRAMISU';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Customers, self::TiramisuSold];
    }

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtoupper(trim($value)));
    }

    /** Valeur à classer, extraite d'une performance de boutique. */
    public function valueOf(ShopPerformance $shop): float
    {
        return match ($this) {
            self::Customers => (float) $shop->customers,
            self::TiramisuSold => (float) $shop->tiramisuSold,
        };
    }

    public function labelKey(): string
    {
        return 'network.metrics.' . $this->value;
    }
}
