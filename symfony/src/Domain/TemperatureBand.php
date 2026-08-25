<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * La température, en tranches — l'autre moitié du temps qu'il fait.
 *
 * ── Pourquoi le ciel ne suffit pas
 *
 * « Soleil » ne dit pas la même chose en février et en août. Un dimanche
 * ensoleillé à 4 °C et un dimanche ensoleillé à 32 °C ne vident pas le même
 * rayon : l'un pousse au café chaud, l'autre au dessert glacé. Le module ne
 * connaissait que le ciel ; il lui manquait le thermomètre.
 *
 * ── Six tranches, et pas une de plus
 *
 * Les bornes sont celles de l'atelier. Elles ne sont pas réglables, et c'est
 * délibéré : une borne mobile change le sens de tout l'historique — « la
 * tranche 18–25 » ne voudrait plus dire la même chose d'une saison à l'autre,
 * et l'on comparerait des pourcentages qui ne parlent plus du même temps.
 * Ce qui se règle, c'est le POURCENTAGE de chaque tranche.
 *
 * ── La borne basse est INCLUSE, la haute exclue
 *
 * 10 °C tombe dans « 10–18 », jamais dans « 0–10 ». Sans cette règle, une
 * température pile sur la borne appartiendrait à deux tranches, et le calcul
 * dépendrait de l'ordre dans lequel on les a écrites.
 */
enum TemperatureBand: string
{
    case Freezing = 'FREEZING';
    case Cold = 'COLD';
    case Mild = 'MILD';
    case Pleasant = 'PLEASANT';
    case Warm = 'WARM';
    case Hot = 'HOT';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Freezing, self::Cold, self::Mild, self::Pleasant, self::Warm, self::Hot];
    }

    public static function fromLoose(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtoupper(trim($value))) ?? self::Mild) : self::Mild;
    }

    /**
     * La tranche d'une température, en degrés Celsius.
     *
     * Rend null pour une température absente : une prévision sans thermomètre
     * ne doit pas se voir attribuer une tranche par défaut, qui appliquerait
     * une correction que rien ne justifie.
     */
    public static function of(?float $celsius): ?self
    {
        if ($celsius === null || !is_finite($celsius)) {
            return null;
        }

        return match (true) {
            $celsius < 0.0 => self::Freezing,
            $celsius < 10.0 => self::Cold,
            $celsius < 18.0 => self::Mild,
            $celsius < 25.0 => self::Pleasant,
            $celsius < 30.0 => self::Warm,
            default => self::Hot,
        };
    }

    /**
     * Borne basse, INCLUSE. Null pour la première tranche, sans plancher.
     *
     * Nommée `lowerBound` et non `from` : `from` est déjà la fabrique statique
     * de toute énumération typée de PHP, et la redéclarer est une erreur
     * fatale.
     */
    public function lowerBound(): ?int
    {
        return match ($this) {
            self::Freezing => null,
            self::Cold => 0,
            self::Mild => 10,
            self::Pleasant => 18,
            self::Warm => 25,
            self::Hot => 30,
        };
    }

    /** Borne haute, EXCLUE. Null pour la dernière tranche, sans plafond. */
    public function upperBound(): ?int
    {
        return match ($this) {
            self::Freezing => 0,
            self::Cold => 10,
            self::Mild => 18,
            self::Pleasant => 25,
            self::Warm => 30,
            self::Hot => null,
        };
    }

    /**
     * La correction de départ : AUCUNE.
     *
     * Zéro pour les six, et ce n'est pas une facilité. Le pourcentage d'une
     * tranche se mesure sur les ventes d'une boutique ; l'inventer ici aurait
     * fait produire selon une intuition de développeur, avec l'autorité d'un
     * réglage livré. Zéro ne change rien tant que l'atelier n'a pas décidé.
     */
    public function defaultPercent(): float
    {
        return 0.0;
    }

    /** L'icône de la tranche — du flocon au soleil plein. */
    public function icon(): string
    {
        return match ($this) {
            self::Freezing, self::Cold => 'weather-snow',
            self::Mild => 'weather-cloudy',
            self::Pleasant, self::Warm, self::Hot => 'weather-sunny',
        };
    }
}
