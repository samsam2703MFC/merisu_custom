<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Entrée du journal d'audit (§2 — traçabilité). */
final readonly class AuditEntry
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public string $id,
        public string $at,
        public string $actorId,
        public string $actorRole,
        /** Clé stable : COUNT_SAVED, EVENING_VALIDATED, COUNT_UNLOCKED… */
        public string $action,
        public ?string $workstationId,
        public ?string $businessDate,
        public array $details,
    ) {
    }
}
