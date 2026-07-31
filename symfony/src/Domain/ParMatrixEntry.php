<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

final readonly class ParMatrixEntry
{
    public function __construct(
        public string $id,
        public string $productId,
        public DayOfWeek $dayOfWeek,
        public float $requiredPieces,
        /** null = seuil global ; renseigné = seuil propre à un poste. */
        public ?string $workstationId,
    ) {
    }
}
