<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

final readonly class MaterialDelta
{
    public function __construct(
        public string $materialId,
        public float $theoreticalQty,
        public ?float $realQty,
        /** réel − théorique. null si le réel n'est pas connu. */
        public ?float $delta,
        /** delta / théorique. null si théorique = 0 ou réel inconnu. */
        public ?float $deltaPct,
        public bool $outOfTolerance,
        public bool $partial,
    ) {
    }
}
