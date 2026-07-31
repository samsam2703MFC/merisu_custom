<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

final readonly class ProductionLine
{
    public function __construct(
        public string $productId,
        public string $productCode,
        public float $requiredPieces,
        public float $closingStock,
        /** Besoin avant facteur de perte et arrondi. */
        public float $rawQtyToProduce,
        public float $qtyToProduce,
        /** Aucun seuil défini pour (produit, jour) → à corriger en administration. */
        public bool $missingThreshold,
        /** Stock de clôture non saisi → le calcul reste indicatif. */
        public bool $missingClosingStock,
    ) {
    }
}
