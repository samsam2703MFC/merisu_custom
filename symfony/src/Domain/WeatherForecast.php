<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * La semaine qui vient, telle qu'OpenWeatherMap l'annonce.
 *
 * ── Sept jours, alors que la réponse en porte huit
 *
 * OpenWeatherMap rend aujourd'hui plus sept. Or la table des seuils est une
 * semaine TYPE, indexée par jour de semaine : garder les huit aurait fait
 * revenir le jour d'aujourd'hui en dernière position, et la prévision de
 * lundi prochain aurait écrasé celle de ce lundi-ci. Sept jours à partir
 * d'aujourd'hui, c'est exactement un tour de semaine, chaque jour une fois.
 *
 * ── Le passé est jeté
 *
 * Une réponse gardée en cache et relue le lendemain porte encore la journée de
 * la veille. L'appliquer aurait posé sur mardi prochain le temps de mardi
 * dernier — une prévision périmée est pire qu'une prévision absente, parce
 * qu'elle a l'air d'une prévision.
 */
final readonly class WeatherForecast
{
    /**
     * Un tour de semaine complet, pas un de plus.
     *
     * Voir l'en-tête : au-delà, un jour de semaine reviendrait deux fois et le
     * second effacerait le premier.
     */
    public const DAYS = 7;

    /** @param list<ForecastDay> $days */
    private function __construct(
        public array $days,
        /** Le fuseau annoncé par l'hôte — « Europe/Warsaw ». Vide s'il se tait. */
        public string $timezone,
    ) {
    }

    /**
     * @param array<string, mixed> $payload la réponse One Call, décodée
     * @param string               $today   date du jour de la boutique (Y-m-d)
     */
    public static function fromHost(array $payload, string $today): self
    {
        $decalage = is_numeric($payload['timezone_offset'] ?? null) ? (int) $payload['timezone_offset'] : 0;
        $lignes = is_array($payload['daily'] ?? null) ? $payload['daily'] : [];

        $jours = [];

        foreach ($lignes as $ligne) {
            if (!is_array($ligne)) {
                continue;
            }

            $jour = ForecastDay::fromHost($ligne, $decalage);

            // Une date déjà vue l'emporte sur celle qui suit : l'hôte rend le
            // tableau dans l'ordre, et le premier passage est le bon.
            if ($jour !== null && $jour->date >= $today && !isset($jours[$jour->date])) {
                $jours[$jour->date] = $jour;
            }
        }

        ksort($jours);

        return new self(
            array_slice(array_values($jours), 0, self::DAYS),
            is_string($payload['timezone'] ?? null) ? trim($payload['timezone']) : '',
        );
    }

    /**
     * Une prévision reconstruite depuis le cache.
     *
     * @param list<ForecastDay> $days
     */
    public static function of(array $days, string $timezone = ''): self
    {
        usort($days, static fn (ForecastDay $a, ForecastDay $b): int => $a->date <=> $b->date);

        return new self(array_slice($days, 0, self::DAYS), $timezone);
    }

    public function isEmpty(): bool
    {
        return $this->days === [];
    }

    /**
     * Le temps annoncé, rangé par jour de semaine.
     *
     * C'est la forme qu'attend la table des seuils. Un jour de semaine absent
     * de la prévision reste absent de ce tableau : l'appelant garde alors la
     * saisie en place, plutôt que d'y poser un « couvert » qui passerait pour
     * une prévision.
     *
     * @return array<string, WeatherKind>
     */
    public function byDayOfWeek(): array
    {
        $sortie = [];

        foreach ($this->days as $jour) {
            $sortie[$jour->dayOfWeek->value] = $jour->kind;
        }

        return $sortie;
    }

    /**
     * La journée annoncée pour une date donnée, ou null.
     */
    public function on(string $date): ?ForecastDay
    {
        foreach ($this->days as $jour) {
            if ($jour->date === $date) {
                return $jour;
            }
        }

        return null;
    }
}
