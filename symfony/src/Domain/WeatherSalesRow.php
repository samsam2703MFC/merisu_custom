<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une ligne de l'analyse ventes × météo : ce qui s'est vendu sous une condition.
 *
 * L'ÉCART est ce qu'on vient chercher, pas la moyenne. « 344 tiramisus par jour
 * au-dessus de 25 °C » ne dit rien tant qu'on ignore ce que vaut un jour
 * ordinaire ; « +19 % » se reporte directement dans un stock minimum.
 *
 * L'écart peut être ABSENT, et c'est le point le plus important de cette
 * classe. Une tranche observée trois jours donne un pourcentage aussi précis
 * qu'un dé à six faces : trois journées, dont peut-être un dimanche de
 * décembre. Le rendre `null` plutôt que de le calculer quand même évite qu'un
 * chiffre sans fondement se retrouve dans les seuils, où plus personne ne
 * saura qu'il reposait sur trois jours.
 */
final readonly class WeatherSalesRow
{
    public function __construct(
        public string $key,
        /** Journées réellement observées sous cette condition. */
        public int $days,
        public float $averageUnits,
        public float $averageRevenue,
        /** Écart en % à la journée ordinaire, ou null si trop peu de jours. */
        public ?float $deviation,
    ) {
    }

    /** Y a-t-il de quoi conclure ? */
    public function isReliable(): bool
    {
        return $this->deviation !== null;
    }

    /**
     * L'écart tel qu'on l'écrit : « +19 % », « −7 % », « = ».
     *
     * Le signe est PORTÉ, y compris le plus : sans lui, « 19 % » se lit comme
     * une part du total et non comme un écart.
     */
    public function deviationLabel(): string
    {
        if ($this->deviation === null) {
            return '—';
        }

        if (abs($this->deviation) < 0.5) {
            return '=';
        }

        // Le signe moins TYPOGRAPHIQUE : le trait d'union se confond avec une
        // césure en fin de ligne, et « -7 % » y devient « 7 % ».
        return ($this->deviation > 0 ? '+' : '−') . number_format(abs($this->deviation), 0, ',', ' ') . ' %';
    }
}
