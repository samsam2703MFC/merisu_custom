<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * Résultats d'une boutique sur une période — provient de la CAISSE, pas de ce
 * module.
 *
 * Chiffre d'affaires, nombre de clients et tiramisu vendus ne sont nulle part
 * dans l'inventaire : celui-ci compte des stocks, il ne connaît ni les
 * encaissements ni les tickets. Ces trois chiffres arrivent donc du système de
 * caisse, via `ShopRankingServiceInterface`.
 */
final readonly class ShopPerformance
{
    public function __construct(
        public string $id,
        public string $name,
        /** Code pays ISO 3166-1 alpha-2 : PL, FR, IT… Sert au classement national. */
        public string $country,
        public float $revenue,
        public int $customers,
        public int $tiramisuSold,
        /** Code ISO 4217. Un réseau international ne facture pas partout en euros. */
        public string $currency = 'EUR',
    ) {
    }

    /**
     * Panier moyen — déduit, jamais fourni.
     *
     * Utile à l'écran pour situer une boutique qui vend peu mais cher, mais il
     * ne sert PAS de critère de classement : le client a retenu le chiffre
     * d'affaires, les clients et les tiramisu.
     */
    public function averageBasket(): ?float
    {
        return $this->customers > 0 ? $this->revenue / $this->customers : null;
    }
}
