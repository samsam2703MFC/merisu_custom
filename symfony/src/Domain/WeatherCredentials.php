<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qu'il faut pour demander la météo : une clé, et un endroit.
 *
 * ── L'endroit n'est pas un détail
 *
 * OpenWeatherMap ne connaît pas les boutiques, il connaît des coordonnées. Une
 * clé sans coordonnées ne rend rien ; des coordonnées fausses rendent la
 * météo de quelqu'un d'autre, sans jamais échouer. C'est le pire des cas —
 * l'écran affiche alors une prévision plausible pour une ville où personne ne
 * travaille — d'où le libellé du lieu, saisi à côté : il n'entre dans aucun
 * appel, il sert uniquement à ce qu'on relise « Varsovie » et non
 * « 52,23 / 21,01 ».
 *
 * ── La clé se traite comme un secret
 *
 * Elle est facturée à l'appel. Elle est donc chiffrée en base et jamais
 * réaffichée, comme le secret de la caisse et pour la même raison.
 */
final readonly class WeatherCredentials
{
    public const VERSION_3 = '3.0';

    public const VERSION_4 = '4.0';

    /**
     * Une version connue, ou 3.0.
     *
     * On ne laisse pas passer une valeur libre : elle décide du chemin appelé,
     * et une coquille aurait donné un 404 facturé au lieu d'une prévision.
     */
    public static function cleanVersion(mixed $value): string
    {
        return is_string($value) && trim($value) === self::VERSION_4 ? self::VERSION_4 : self::VERSION_3;
    }

    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public float $latitude,
        public float $longitude,
        /** Le nom que l'écran affiche. Aucun appel ne s'en sert. */
        public string $place = '',
        /**
         * Écrire la prévision dans la semaine type dès qu'elle est reçue.
         *
         * Faux par défaut : la prévision ARRIVE, elle ne s'impose pas. Poser
         * vrai à l'installation aurait écrasé une saisie manuelle sans que
         * personne l'ait demandé.
         */
        public bool $autoApply = false,
        /**
         * La version de One Call visée — « 3.0 » ou « 4.0 ».
         *
         * Les deux existent, et les deux exigent le MÊME abonnement « One Call
         * by Call » : passer de l'une à l'autre ne fait pas disparaître un
         * refus, le message de l'hôte est mot pour mot le même. Le choix est
         * donc un réglage, pas un remède.
         *
         * 3.0 par défaut : c'est celle qui est vérifiée de bout en bout.
         */
        public string $apiVersion = self::VERSION_3,
        /** Vrai quand ces valeurs viennent de l'écran, faux quand du serveur. */
        public bool $fromScreen = false,
    ) {
    }

    /**
     * Des coordonnées utilisables.
     *
     * Le point (0, 0) est REFUSÉ : il tombe dans le golfe de Guinée, et c'est
     * exactement ce que rend un formulaire dont les deux champs sont restés
     * vides. L'accepter aurait donné une prévision marine présentée comme
     * celle de la boutique.
     */
    public function hasCoordinates(): bool
    {
        return abs($this->latitude) <= 90.0
            && abs($this->longitude) <= 180.0
            && (abs($this->latitude) > 0.0001 || abs($this->longitude) > 0.0001);
    }

    public function isComplete(): bool
    {
        return trim($this->apiKey) !== '' && $this->hasCoordinates();
    }

    /**
     * Ce que l'écran a le droit de montrer.
     *
     * La clé n'en fait pas partie, pas même tronquée : on dit seulement
     * qu'elle est posée.
     *
     * @return array{latitude: float, longitude: float, place: string, autoApply: bool, apiVersion: string, hasKey: bool}
     */
    public function display(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'place' => $this->place,
            'autoApply' => $this->autoApply,
            'apiVersion' => self::cleanVersion($this->apiVersion),
            'hasKey' => trim($this->apiKey) !== '',
        ];
    }
}
