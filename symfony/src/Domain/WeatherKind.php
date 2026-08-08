<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Le temps qu'il fait, et ce que cela change au stock minimum.
 *
 * Un tiramisu ne se vend pas au même rythme sous la pluie et au soleil. La
 * moyenne des six dernières semaines dit ce qui s'écoule d'ordinaire ; le
 * temps qu'il fera demain corrige cette moyenne vers le haut ou vers le bas.
 *
 * ── Les pourcentages ne sont PAS dans ce code
 *
 * Ils vivent en base et se règlent en administration. C'est une exigence du
 * cahier des charges (§2, aucune donnée métier codée en dur), mais surtout du
 * bon sens : le coefficient d'une averse à Varsovie n'est pas celui d'une
 * averse à Palerme, et l'atelier doit pouvoir l'ajuster après une saison sans
 * attendre un déploiement.
 *
 * Les valeurs ci-dessous ne sont que le POINT DE DÉPART inscrit à
 * l'installation. Deux d'entre elles ont été fixées par la boutique — pluie
 * +5 %, soleil −9 % ; les autres partent à zéro, c'est-à-dire « aucun effet
 * déclaré ». Inventer un chiffre pour la neige aurait fait passer une
 * supposition pour une mesure.
 */
enum WeatherKind: string
{
    case Sunny = 'SUNNY';
    case Cloudy = 'CLOUDY';
    case Rain = 'RAIN';
    case Snow = 'SNOW';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Sunny, self::Cloudy, self::Rain, self::Snow];
    }

    /** Le temps ordinaire — la référence, sans correction. */
    public static function default(): self
    {
        return self::Cloudy;
    }

    public static function fromLoose(mixed $value): self
    {
        return self::tryFrom(is_scalar($value) ? strtoupper((string) $value) : '') ?? self::default();
    }

    /**
     * Correction de départ, en pourcentage, à l'installation seulement.
     *
     * Zéro = aucun effet déclaré, et non « effet nul mesuré ».
     */
    public function defaultPercent(): float
    {
        return match ($this) {
            self::Sunny => -9.0,
            self::Rain => 5.0,
            self::Cloudy, self::Snow => 0.0,
        };
    }

    public function icon(): string
    {
        return 'weather-' . strtolower($this->value);
    }
}
