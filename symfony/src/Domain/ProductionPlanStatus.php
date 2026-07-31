<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Statut d'un plan de production. */
enum ProductionPlanStatus: string
{
    case Draft = 'DRAFT';
    case Frozen = 'FROZEN';
    case Cancelled = 'CANCELLED';
}
