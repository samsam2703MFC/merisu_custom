<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

final readonly class ValidationIssue
{
    public function __construct(
        /** Clé stable traduite par l'interface : MISSING_QTY, MISSING_PHOTO… */
        public string $code,
        /** Produit concerné ; null pour un problème global. */
        public ?string $productId = null,
    ) {
    }
}
