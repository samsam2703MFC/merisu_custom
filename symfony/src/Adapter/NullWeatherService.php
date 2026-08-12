<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\WeatherCredentials;
use Merisu\Inventory\Domain\WeatherForecast;

/**
 * Implémentation d'attente : elle ne demande rien, et le dit.
 *
 * `OpenWeatherService` sans clé se comporte déjà ainsi ; celle-ci existe pour
 * le cas où l'on veut la certitude qu'AUCUN appel facturé ne sort, même si une
 * clé traîne dans l'environnement. Il suffit de la brancher à sa place dans
 * `config/services.yaml`.
 */
final class NullWeatherService implements WeatherServiceInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function credentials(): WeatherCredentials
    {
        return new WeatherCredentials('', 0.0, 0.0);
    }

    public function forecast(string $today, string $lang = 'en'): WeatherForecast
    {
        throw new WeatherUnavailable('admin.weather.notConfigured');
    }
}
