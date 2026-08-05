<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Arrêt de production sur un poste.
 *
 * Un arrêt n'est pas un réglage : c'est un événement daté, décidé par
 * quelqu'un, pour un motif. Panne de four, rupture de matière, contrôle
 * sanitaire — le lendemain, il faudra pouvoir dire qui a arrêté quoi et
 * pourquoi. D'où l'historique plutôt qu'un simple interrupteur : chaque arrêt
 * garde sa trace même une fois levé.
 *
 * `liftedAt` à null signifie que l'arrêt COURT ENCORE. C'est le seul état qui
 * bloque la production.
 */
final readonly class ProductionStop
{
    public function __construct(
        public string $id,
        public string $workstationId,
        public string $reason,
        public string $startedBy,
        public string $startedAt,
        public ?string $liftedBy = null,
        public ?string $liftedAt = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->liftedAt === null;
    }
}
