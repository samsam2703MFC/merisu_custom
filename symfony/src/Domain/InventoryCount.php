<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Comptage physique d'un produit à un moment donné. */
final readonly class InventoryCount
{
    /** @param list<CountPhoto> $photos */
    public function __construct(
        public string $id,
        /** Date métier `YYYY-MM-DD` (jour de production, pas l'horodatage). */
        public string $date,
        public string $workstationId,
        public string $consultantId,
        public string $productId,
        public CountMoment $moment,
        public float $qty,
        /** Production entrée en stock dans la journée (optionnelle, §5.1). */
        public ?float $producedQty,
        public bool $validated,
        public ?string $validatedAt,
        public ?string $validatedBy,
        public array $photos,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
