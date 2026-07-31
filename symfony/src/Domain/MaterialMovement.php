<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Consommation réelle de matière constatée sur une période (§6). */
final readonly class MaterialMovement
{
    public function __construct(
        public string $id,
        public string $date,
        public string $materialId,
        /** Quantité réellement consommée (variation de stock). */
        public float $realQty,
        public ?string $workstationId,
        public string $recordedBy,
        public string $recordedAt,
        public ?string $note,
    ) {
    }
}
