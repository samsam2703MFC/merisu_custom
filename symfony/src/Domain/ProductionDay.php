<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qu'il faut produire un jour donné, et par quel chemin on y arrive.
 *
 * Les trois facteurs sont GARDÉS, pas seulement leur produit. Un plan de
 * production qui ne rend qu'un nombre ne se conteste pas : « 274 » ne dit ni
 * sur combien de jeudis il repose, ni ce que la météo y a mis. Les garder
 * permet à l'écran de déplier le calcul, et à quiconque le trouve douteux de
 * voir lequel des trois facteurs l'a fait bouger.
 */
final readonly class ProductionDay
{
    public function __construct(
        public string $date,
        public DayOfWeek $dayOfWeek,
        /** Moyenne des mêmes jours de semaine, sur la période observée. */
        public float $base,
        /** Combien de fois ce jour de semaine a été observé. */
        public int $observedDays,
        /** Correction météo en %, ou null si le temps de ce jour est inconnu. */
        public ?float $weatherPercent,
        /** Le temps qui a servi, pour que l'écran puisse le nommer. */
        public ?TemperatureBand $band,
        public ?WeatherKind $kind,
        /** Croissance visée, en %, pour cette boutique et ce mois. */
        public float $targetPercent,
        /** Le résultat, arrondi à l'entier SUPÉRIEUR. */
        public int $pieces,
    ) {
    }

    /**
     * Y a-t-il de quoi produire un plan digne de ce nom ?
     *
     * Une base tirée d'une ou deux semaines n'est pas une moyenne, c'est un
     * souvenir. L'écran l'affiche quand même — le vendeur doit bien produire
     * quelque chose — mais il le signale, faute de quoi le nombre passerait
     * pour aussi solide que les autres.
     */
    public function isSolid(): bool
    {
        return $this->observedDays >= ProductionForecast::MIN_OBSERVATIONS;
    }

    /** La météo a-t-elle pu être prise en compte ? */
    public function hasWeather(): bool
    {
        return $this->weatherPercent !== null;
    }

    /**
     * Le calcul écrit en toutes lettres, pour le bas du bon de production.
     *
     * Sert de trace : un bon imprimé le matin doit pouvoir se relire le soir
     * sans rouvrir l'écran qui l'a produit.
     */
    public function formula(): string
    {
        $morceaux = [\sprintf('%s', number_format($this->base, 1, ',', ' '))];

        if ($this->weatherPercent !== null && abs($this->weatherPercent) >= 0.05) {
            $morceaux[] = \sprintf('météo %+.1f %%', $this->weatherPercent);
        }

        if (abs($this->targetPercent) >= 0.05) {
            $morceaux[] = \sprintf('objectif %+.1f %%', $this->targetPercent);
        }

        return str_replace('.', ',', implode(' · ', $morceaux));
    }
}
