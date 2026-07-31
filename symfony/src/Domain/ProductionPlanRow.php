<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Ligne de plan de production, figée à la validation du soir. */
final readonly class ProductionPlanRow
{
    public function __construct(
        public string $id,
        /** Date visée (le lendemain du comptage), `YYYY-MM-DD`. */
        public string $forDate,
        public string $productId,
        public string $workstationId,
        public float $requiredPieces,
        public float $closingStock,
        public float $qtyToProduce,
        public string $computedAt,
        public ProductionPlanStatus $status,
        public bool $missingThreshold,
    ) {
    }
}
