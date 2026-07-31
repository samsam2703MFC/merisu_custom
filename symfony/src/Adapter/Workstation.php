<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/** Poste de travail (« stanowisko ») — provient du module Consultant existant. */
final readonly class Workstation
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $active,
    ) {
    }
}
