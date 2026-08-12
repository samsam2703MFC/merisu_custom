<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\WeatherCredentials;
use Merisu\Inventory\Domain\WeatherForecast;

/**
 * D'où vient le temps qu'il fera.
 *
 * Un adaptateur, comme la caisse et les nomenclatures : le module ne connaît
 * pas OpenWeatherMap, il connaît « quelqu'un qui sait le temps de la semaine ».
 * Remplacer ce fournisseur — un service national, une station sur le toit —
 * ne doit toucher qu'un alias dans `config/services.yaml`.
 */
interface WeatherServiceInterface
{
    public function isConfigured(): bool;

    public function credentials(): WeatherCredentials;

    /**
     * La semaine qui vient, à partir d'aujourd'hui.
     *
     * @param string $today date du jour de la boutique (Y-m-d)
     * @param string $lang  langue des libellés — « fr », « pl », « it », « es »
     *
     * @throws WeatherUnavailable
     */
    public function forecast(string $today, string $lang = 'en'): WeatherForecast;
}
