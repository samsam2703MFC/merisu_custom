<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Le code météo d'OpenWeatherMap, ramené aux quatre temps de la boutique.
 *
 * ── Pourquoi quatre, et pas cinquante
 *
 * OpenWeatherMap distingue cinquante-cinq conditions : bruine légère, bruine
 * verglaçante, averse de bruine, pluie modérée, pluie très forte, tornade… Ce
 * module n'en connaît que quatre, parce que c'est tout ce que l'atelier a
 * chiffré : une correction par temps, réglée en administration. Ramener une
 * « averse de bruine » à un cinquième pourcentage aurait demandé à quelqu'un
 * d'inventer ce pourcentage.
 *
 * ── La règle de ramassage
 *
 * · 6xx — neige, sous toutes ses formes ;
 * · 2xx à 5xx — orage, bruine, pluie : de l'eau tombe, le trottoir est mouillé ;
 * · 7xx — brume, brouillard, sable, fumée : ni soleil ni eau, donc « couvert » ;
 * · 800 — ciel clair, et 801 (« quelques nuages », un quart du ciel au plus) :
 *   du soleil ;
 * · 802 à 804 — de la moitié du ciel à sa totalité : couvert.
 *
 * 801 range du côté du soleil, et c'est la seule frontière discutable. Un ciel
 * couvert à un quart reste un ciel où l'on s'assoit en terrasse — et c'est la
 * terrasse qui vide les bacs, pas le nuage.
 */
final class WeatherCode
{
    /**
     * Un code OpenWeatherMap, ou null s'il ne tombe dans aucun groupe connu.
     *
     * Null, et non « couvert par défaut » : un code inconnu est un code que
     * cette maison n'a pas prévu, et le confondre avec une prévision réelle
     * aurait fait passer un trou pour une mesure. L'appelant décide.
     */
    public static function toKind(int $id): ?WeatherKind
    {
        return match (true) {
            $id >= 600 && $id < 700 => WeatherKind::Snow,
            $id >= 200 && $id < 600 => WeatherKind::Rain,
            $id >= 700 && $id < 800 => WeatherKind::Cloudy,
            $id === 800, $id === 801 => WeatherKind::Sunny,
            $id > 801 && $id < 900 => WeatherKind::Cloudy,
            default => null,
        };
    }

    /**
     * Le temps d'une journée, à partir de la liste `weather` d'OpenWeatherMap.
     *
     * La liste porte d'ordinaire une seule condition, mais elle peut en porter
     * plusieurs — « ciel clair » ET « averse ». On garde alors la PLUS
     * MARQUANTE, celle qui décide si les gens sortent : la neige, puis la
     * pluie, puis le couvert, le soleil en dernier. Prendre la première venue
     * aurait pu annoncer du soleil un jour de grêle.
     *
     * @param mixed $weather la valeur brute du champ `weather`
     */
    public static function dominant(mixed $weather): ?WeatherKind
    {
        if (!is_array($weather)) {
            return null;
        }

        $trouve = null;

        foreach ($weather as $condition) {
            if (!is_array($condition) || !is_numeric($condition['id'] ?? null)) {
                continue;
            }

            $temps = self::toKind((int) $condition['id']);

            if ($temps !== null && ($trouve === null || self::weight($temps) > self::weight($trouve))) {
                $trouve = $temps;
            }
        }

        return $trouve;
    }

    /** Ce qui l'emporte quand deux conditions se disputent la journée. */
    private static function weight(WeatherKind $kind): int
    {
        return match ($kind) {
            WeatherKind::Snow => 4,
            WeatherKind::Rain => 3,
            WeatherKind::Cloudy => 2,
            WeatherKind::Sunny => 1,
        };
    }
}
