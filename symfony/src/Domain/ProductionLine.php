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
        /**
         * Le requis vient-il de la moyenne des six semaines, ou du seuil saisi ?
         *
         * Porté jusqu'à l'écran : une quantité qui change toute seule d'un jour
         * à l'autre inquiète, tant qu'on ne sait pas qu'elle SUIT la demande.
         */
        public bool $fromHistory = false,
    ) {
    }
}
