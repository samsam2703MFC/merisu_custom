<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\Role;

/** Consultant — provient du module « Consultant / Stanowisko » existant. */
final readonly class Consultant
{
    public function __construct(
        public string $id,
        public string $displayName,
        public Role $role,
        /** Poste pré-affecté, si le module existant en fournit un. */
        public ?string $defaultWorkstationId,
        public bool $active,
    ) {
    }
}
