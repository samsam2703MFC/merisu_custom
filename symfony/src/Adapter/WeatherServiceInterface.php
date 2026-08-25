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

    /**
     * Le temps qu'il a FAIT, sur un intervalle passé.
     *
     * La prévision annonce sept jours ; celle-ci relit le passé. C'est la
     * moitié manquante de toute corrélation entre les ventes et le temps : on
     * a des mois de ventes à la journée, et rien en face.
     *
     * ⚠️ Tous les fournisseurs ne la servent pas, et pas sous le même
     * abonnement. Une implémentation qui ne sait pas remonter le temps lève
     * `WeatherUnavailable` avec `admin.weather.noHistory` — elle ne rend PAS un
     * tableau vide, qui se serait lu comme « il n'a rien fait ces jours-là ».
     *
     * @return list<\Merisu\Inventory\Domain\ForecastDay>
     *
     * @throws WeatherUnavailable
     */
    public function history(string $from, string $to, string $lang = 'en'): array;
}
