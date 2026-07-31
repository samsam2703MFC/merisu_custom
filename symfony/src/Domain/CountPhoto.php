<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Photo jointe à un comptage (preuve du stock du soir). */
final readonly class CountPhoto
{
    public function __construct(
        public string $id,
        public string $inventoryCountId,
        public string $url,
        public string $takenAt,
    ) {
    }
}
