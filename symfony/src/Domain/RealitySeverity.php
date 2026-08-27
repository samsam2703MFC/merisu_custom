<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * La gravité d'une dérive réel / théorique.
 *
 * `Unknown` n'est pas une quatrième nuance de gravité : c'est l'absence de
 * mesure. Le confondre avec `Ok` afficherait « tout va bien » là où l'on n'a
 * simplement rien relevé — le mensonge le plus tranquille d'un tableau de bord.
 */
enum RealitySeverity: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Danger = 'danger';
    case Unknown = 'unknown';
}
