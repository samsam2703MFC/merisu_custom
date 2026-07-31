<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Jour de la semaine, en clé stable et indépendante de la langue.
 * La semaine démarre au lundi, conformément à la matrice des seuils.
 */
enum DayOfWeek: string
{
    case Mon = 'MON';
    case Tue = 'TUE';
    case Wed = 'WED';
    case Thu = 'THU';
    case Fri = 'FRI';
    case Sat = 'SAT';
    case Sun = 'SUN';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Mon, self::Tue, self::Wed, self::Thu, self::Fri, self::Sat, self::Sun];
    }
}
