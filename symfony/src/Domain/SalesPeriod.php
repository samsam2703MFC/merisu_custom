<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Les quatre façons de regarder les ventes.
 *
 * Chacune répond à une question différente, et l'atelier les pose toutes :
 *
 * · JOUR — « combien hier ? » ; c'est le grain du relevé, et celui du plan ;
 * · JOUR DE SEMAINE — « combien un mardi ? » ; la question qui décide de la
 *   production, parce qu'une boutique ne vend pas le mardi ce qu'elle vend le
 *   samedi ;
 * · SEMAINE — « la semaine dernière a-t-elle été bonne ? » ; la maille où le
 *   bruit des jours s'efface et où une tendance apparaît ;
 * · MOIS — « où en est-on de l'objectif ? ».
 */
enum SalesPeriod: string
{
    case Day = 'DAY';
    case Weekday = 'WEEKDAY';
    case Week = 'WEEK';
    case Month = 'MONTH';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Day, self::Weekday, self::Week, self::Month];
    }

    public static function fromLoose(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtoupper(trim($value))) ?? self::Day) : self::Day;
    }

    /**
     * La clé sous laquelle une date se range.
     *
     * La semaine suit la norme ISO — lundi ouvre, et la semaine appartient à
     * l'année qui contient son jeudi. Employer le numéro de semaine sans son
     * année aurait mélangé la semaine 1 de deux années au premier janvier.
     */
    public function keyFor(string $date): string
    {
        $instant = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));

        return match ($this) {
            self::Day => $instant->format('Y-m-d'),
            // 1 = lundi, 7 = dimanche, comme DayOfWeek et comme la caisse.
            self::Weekday => $instant->format('N'),
            self::Week => $instant->format('o-\WW'),
            self::Month => $instant->format('Y-m'),
        };
    }

    /**
     * Le tri va-t-il du plus récent au plus ancien ?
     *
     * Pour les périodes datées, oui : on regarde d'abord ce qui vient de se
     * passer. Pour le jour de semaine, non — lundi, mardi, mercredi est le
     * seul ordre qu'on lise sans réfléchir.
     */
    public function isChronological(): bool
    {
        return $this !== self::Weekday;
    }
}
