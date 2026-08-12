<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * Le service météo n'a pas répondu, ou a refusé.
 *
 * `detail` porte ce que l'hôte a dit — son code HTTP et son message. Il compte
 * ici plus qu'ailleurs : One Call 3.0 se souscrit SÉPARÉMENT du reste
 * d'OpenWeatherMap, et une clé parfaitement valide pour les autres appels y
 * répond « 401 — Please note that using One Call 3.0 requires a separate
 * subscription ». Sans cette phrase à l'écran, on renvoie quelqu'un vérifier
 * une clé qui n'a rien de faux.
 *
 * La clé n'apparaît jamais dans ce qui remonte : elle ne part que dans la
 * requête.
 */
final class WeatherUnavailable extends \RuntimeException
{
    public function __construct(
        string $messageKey,
        /** Ce que l'hôte a répondu, en clair. Vide s'il n'a rien dit. */
        public readonly string $detail = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($messageKey, 0, $previous);
    }
}
