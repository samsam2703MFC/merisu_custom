<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Le fait qu'un point ait été coché, un jour donné, à un poste donné.
 *
 * §4 — traçabilité : qui, quand, où. Une case cochée sans auteur ni horodatage
 * ne vaut rien le jour où l'on cherche à savoir si le contrôle a réellement
 * eu lieu.
 */
final readonly class ChecklistEntry
{
    public function __construct(
        public string $id,
        public string $businessDate,
        public string $workstationId,
        public string $itemId,
        public bool $checked,
        public string $consultantId,
        public string $checkedAt,
        /** Précision libre : température relevée, anomalie constatée… */
        public ?string $note = null,
    ) {
    }
}
