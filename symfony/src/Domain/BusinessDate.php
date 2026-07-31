<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Dates métier.
 *
 * Toutes les dates métier circulent en chaîne `YYYY-MM-DD`, jamais en objet
 * horodaté : cela évite les décalages de fuseau entre le poste du consultant,
 * le serveur et la base.
 */
final class BusinessDate
{
    public static function isValid(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $date));

        return checkdate($m, $d, $y);
    }

    /** @throws \InvalidArgumentException si la date n'est pas au format attendu. */
    public static function assertValid(string $date): void
    {
        if (!self::isValid($date)) {
            throw new \InvalidArgumentException(sprintf('Date métier invalide (attendu YYYY-MM-DD) : "%s"', $date));
        }
    }

    /**
     * Jour de la semaine d'une date métier.
     * `N` renvoie 1 (lundi) à 7 (dimanche), ce qui correspond à l'ordre de l'enum.
     */
    public static function dayOfWeek(string $date): DayOfWeek
    {
        self::assertValid($date);
        $index = (int) self::immutable($date)->format('N');

        return DayOfWeek::all()[$index - 1];
    }

    /** Jour suivant. Gère nativement le passage de semaine, de mois et d'année. */
    public static function next(string $date): string
    {
        return self::addDays($date, 1);
    }

    /** Jour précédent. */
    public static function previous(string $date): string
    {
        return self::addDays($date, -1);
    }

    public static function addDays(string $date, int $days): string
    {
        self::assertValid($date);

        return self::immutable($date)
            ->modify(sprintf('%+d day', $days))
            ->format('Y-m-d');
    }

    /**
     * Liste inclusive des dates entre `$from` et `$to`.
     *
     * @return list<string>
     */
    public static function range(string $from, string $to): array
    {
        self::assertValid($from);
        self::assertValid($to);

        if ($from > $to) {
            return [];
        }

        $dates = [];
        $cursor = $from;

        // Garde-fou : une période de rapport ne dépasse jamais ~10 ans.
        for ($i = 0; $i < 4000 && $cursor <= $to; ++$i) {
            $dates[] = $cursor;
            $cursor = self::next($cursor);
        }

        return $dates;
    }

    /** Date métier « aujourd'hui » dans le fuseau configuré en administration. */
    public static function today(string $timezone, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            return $now->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
        } catch (\Exception) {
            // Fuseau inconnu : repli sur UTC plutôt que d'empêcher la saisie.
            return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        }
    }

    /** Heure locale `HH:MM` dans le fuseau configuré (choix de l'écran matin/soir). */
    public static function time(string $timezone, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            return $now->setTimezone(new \DateTimeZone($timezone))->format('H:i');
        } catch (\Exception) {
            return $now->setTimezone(new \DateTimeZone('UTC'))->format('H:i');
        }
    }

    /** Midi UTC : insensible aux changements d'heure, qui surviennent la nuit. */
    private static function immutable(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' 12:00:00', new \DateTimeZone('UTC'));
    }
}
