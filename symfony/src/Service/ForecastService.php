<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Adapter\WeatherServiceInterface;
use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\WeatherForecast;
use Merisu\Inventory\Store\Store;

/**
 * La prévision de la semaine : la chercher, la garder, l'appliquer.
 *
 * ── Trois gestes, et non un seul
 *
 * Chercher chez l'hôte coûte un appel facturé. Garder en base ne coûte rien.
 * Appliquer à la semaine type change ce que la boutique produira. Les
 * confondre aurait donné un écran qui, à chaque affichage, aurait consommé du
 * quota ET modifié le plan de production sans que personne l'ait demandé.
 *
 * ── Pourquoi appliquer ne va pas de soi
 *
 * La semaine type (`inv_day_weather`) est une SAISIE : quelqu'un a regardé et
 * décidé. La prévision, elle, arrive toute seule. L'écraser d'office aurait
 * défait ce choix chaque nuit. L'écriture demande donc soit un clic, soit le
 * réglage « appliquer automatiquement » que l'administrateur a coché en
 * connaissance de cause.
 *
 * ── Ce qui n'est PAS annoncé n'est pas touché
 *
 * Sept journées attendues, mais l'hôte peut en rendre moins. Un jour de
 * semaine absent de la prévision garde ce qui était saisi, plutôt que de
 * retomber sur « couvert » : un trou dans la réponse ne doit pas se lire comme
 * une prévision de temps ordinaire.
 */
final readonly class ForecastService
{
    public function __construct(
        private Store $store,
        private WeatherServiceInterface $weather,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->weather->isConfigured();
    }

    /** La prévision en base — gratuite, et c'est elle que les écrans lisent. */
    public function cached(string $today): WeatherForecast
    {
        return $this->store->weatherForecast($today);
    }

    public function fetchedAt(): ?string
    {
        return $this->store->weatherFetchedAt();
    }

    /**
     * Va chercher la prévision chez l'hôte, la garde, et l'applique si demandé.
     *
     * @return array{days: int, applied: int, autoApplied: bool, journalled: int}
     *
     * @throws \Merisu\Inventory\Adapter\WeatherUnavailable
     */
    public function refresh(
        string $today,
        string $lang,
        ?string $actorId = null,
        ?string $actorRole = null,
    ): array {
        $prevision = $this->weather->forecast($today, $lang);
        $this->store->saveWeatherForecast($prevision);

        $auto = $this->weather->credentials()->autoApply;
        $appliques = 0;

        if ($auto) {
            $appliques = $this->apply($prevision, $actorId, $actorRole);
        }

        // La journée du jour entre au JOURNAL, qui garde ce qu'il a fait.
        // Sans cet enregistrement, la prévision se remplace à chaque appel et
        // rien ne subsiste : quatre mois de ventes à la journée, et rien en
        // face pour les expliquer.
        $journalisees = $this->journal($prevision, $today);

        if ($actorId !== null && $actorRole !== null) {
            $this->store->audit($actorId, $actorRole, 'WEATHER_FETCHED', null, null, [
                'days' => count($prevision->days),
                'timezone' => $prevision->timezone,
            ]);
        }

        return [
            'days' => count($prevision->days),
            'applied' => $appliques,
            'autoApplied' => $auto,
            'journalled' => $journalisees,
        ];
    }

    /**
     * Inscrit au journal les journées ÉCHUES ou en cours.
     *
     * Seulement celles-là : demain n'a pas encore eu lieu, et l'inscrire
     * ferait du journal une prévision de plus. Une journée déjà inscrite n'est
     * pas réécrite — ce qui a été observé vaut mieux que ce qui avait été
     * annoncé, et un rafraîchissement ne doit pas repeindre le passé.
     *
     * @return int le nombre de journées ajoutées
     */
    public function journal(WeatherForecast $forecast, string $today): int
    {
        $ajoutees = 0;

        foreach ($forecast->days as $jour) {
            if ($jour->date <= $today && $this->store->recordWeatherDay($jour, 'FORECAST')) {
                ++$ajoutees;
            }
        }

        return $ajoutees;
    }

    /**
     * Rattrape le temps passé auprès du service, et l'inscrit au journal.
     *
     * Les lignes d'historique CORRIGENT celles posées par la prévision : un
     * relevé vaut mieux qu'une annonce faite la veille.
     *
     * @return array{days: int, from: string, to: string}
     *
     * @throws \Merisu\Inventory\Adapter\WeatherUnavailable
     */
    public function backfill(string $from, string $to, string $lang = 'en'): array
    {
        $jours = $this->weather->history($from, $to, $lang);

        foreach ($jours as $jour) {
            $this->store->recordWeatherDay($jour, 'HISTORY');
        }

        return ['days' => count($jours), 'from' => $from, 'to' => $to];
    }

    /**
     * Écrit la prévision dans la semaine type.
     *
     * Ne réécrit QUE ce qui change : un jour déjà réglé sur le temps annoncé
     * n'est pas retouché, et l'historique ne se remplit donc pas de lignes qui
     * ne disent rien.
     *
     * @return int le nombre de jours effectivement changés
     */
    public function apply(
        WeatherForecast $forecast,
        ?string $actorId = null,
        ?string $actorRole = null,
    ): int {
        $actuels = $this->store->dayWeathers();
        $changes = [];

        foreach ($forecast->byDayOfWeek() as $jour => $temps) {
            $dayOfWeek = DayOfWeek::tryFrom($jour);

            if ($dayOfWeek === null || ($actuels[$jour] ?? null) === $temps) {
                continue;
            }

            $this->store->saveDayWeather($dayOfWeek, $temps);
            $changes[$jour] = $temps->value;
        }

        if ($changes !== [] && $actorId !== null && $actorRole !== null) {
            $this->store->audit($actorId, $actorRole, 'WEATHER_APPLIED', null, null, $changes);
        }

        return count($changes);
    }
}
