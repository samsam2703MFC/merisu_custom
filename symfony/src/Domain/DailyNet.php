<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

final readonly class DailyNet
{
    public function __construct(
        public string $date,
        public string $productId,
        public string $workstationId,
        public ?float $openingStock,
        public ?float $closingStock,
        public float $producedQty,
        /** Ouverture + production − clôture. null si une saisie manque. */
        public ?float $netConsumption,
        /** true dès qu'une donnée nécessaire manque : affichage « données partielles ». */
        public bool $partial,
    ) {
    }
}
