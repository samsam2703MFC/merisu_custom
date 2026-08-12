<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une journée de prévision : sa date, son temps, et de quoi le vérifier d'un
 * coup d'œil.
 *
 * ── La température et la probabilité de pluie ne servent à AUCUN calcul
 *
 * Le stock minimum ne dépend que du `kind` — c'est lui qui porte le
 * pourcentage réglé en administration. Les deux autres valeurs sont là pour
 * que la personne qui regarde l'écran puisse juger : « pluie » à 2 % de
 * probabilité et « pluie » à 90 %, ce n'est pas la même journée, et celui qui
 * corrige la prévision à la main doit pouvoir voir laquelle il corrige.
 *
 * ── Le jour de semaine est CALCULÉ, pas reçu
 *
 * La table des seuils est indexée par jour de semaine, pas par date : c'est
 * une semaine type, qui se répète. La prévision, elle, porte des dates. Ce
 * pont-là se fait ici, une fois, plutôt que dans chaque écran qui les
 * rapproche.
 */
final readonly class ForecastDay
{
    private function __construct(
        /** Date locale de la boutique, au format Y-m-d. */
        public string $date,
        public DayOfWeek $dayOfWeek,
        public WeatherKind $kind,
        public ?float $tempMin,
        public ?float $tempMax,
        /** Probabilité de précipitations, de 0 à 100. */
        public int $rainChance,
        /** Le mot d'OpenWeatherMap, dans la langue demandée. Peut être vide. */
        public string $summary,
    ) {
    }

    /**
     * Une ligne du tableau `daily` d'OpenWeatherMap.
     *
     * ── Pourquoi le décalage horaire est indispensable
     *
     * `dt` est un horodatage UTC. À Varsovie en été, la journée du 3 août
     * commence à 22 h UTC le 2 : lire `gmdate('Y-m-d', $dt)` aurait décalé
     * toute la semaine d'un jour, et fait produire le lundi ce qu'il fallait
     * le mardi. Le décalage vient du champ `timezone_offset` de la réponse,
     * qui tient déjà compte de l'heure d'été.
     *
     * Rend null quand la ligne n'est pas exploitable — horodatage absent ou
     * code météo inconnu. Une journée devinée vaut moins que pas de journée du
     * tout : celle-ci laisse la saisie manuelle en place, l'autre l'écrase.
     *
     * @param array<string, mixed> $row
     */
    public static function fromHost(array $row, int $offsetSeconds): ?self
    {
        if (!is_numeric($row['dt'] ?? null)) {
            return null;
        }

        $temps = WeatherCode::dominant($row['weather'] ?? null);

        if ($temps === null) {
            return null;
        }

        $local = (int) $row['dt'] + $offsetSeconds;
        $jour = DayOfWeek::tryFrom(strtoupper(gmdate('D', $local)));

        if ($jour === null) {
            return null;
        }

        // `temp` est un objet — jour, matin, soir, nuit, minimum, maximum. Les
        // deux bornes suffisent : personne ne décide d'une production sur la
        // température de 3 h du matin.
        $temperatures = is_array($row['temp'] ?? null) ? $row['temp'] : [];

        return new self(
            gmdate('Y-m-d', $local),
            $jour,
            $temps,
            self::number($temperatures['min'] ?? null),
            self::number($temperatures['max'] ?? null),
            // `pop` va de 0 à 1 chez OpenWeatherMap ; l'écran parle en
            // pourcentage, comme tous les autres chiffres de ce module.
            (int) round(100 * min(1.0, max(0.0, self::number($row['pop'] ?? null) ?? 0.0))),
            is_string($row['summary'] ?? null) ? trim($row['summary']) : '',
        );
    }

    /** Fabrique de test et de relecture : une journée déjà connue. */
    public static function of(
        string $date,
        DayOfWeek $dayOfWeek,
        WeatherKind $kind,
        ?float $tempMin = null,
        ?float $tempMax = null,
        int $rainChance = 0,
        string $summary = '',
    ): self {
        return new self($date, $dayOfWeek, $kind, $tempMin, $tempMax, max(0, min(100, $rainChance)), $summary);
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
