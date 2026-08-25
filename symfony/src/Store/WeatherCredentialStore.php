<?php

declare(strict_types=1);

namespace Merisu\Inventory\Store;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Domain\WeatherCredentials;
use Merisu\Inventory\Service\SecretBox;

/**
 * La clé météo et l'endroit de la boutique, saisis en administration.
 *
 * Même dispositif que les identifiants de caisse : une seule ligne, l'écran
 * l'emporte sur le serveur, la clé est chiffrée avec une clé dérivée
 * d'`APP_SECRET` qui vit hors de la base — une base dérobée seule ne livre donc
 * pas de quoi consommer le quota de quelqu'un d'autre.
 */
final readonly class WeatherCredentialStore
{
    /** Une seule ligne : une installation regarde le ciel d'un seul endroit. */
    private const ROW_ID = 1;

    public function __construct(
        private Connection $db,
        private SecretBox $box,
    ) {
    }

    /**
     * Les réglages en vigueur : l'écran d'abord, le serveur ensuite.
     *
     * @param WeatherCredentials $fallback ce que porte l'environnement
     */
    public function effective(WeatherCredentials $fallback): WeatherCredentials
    {
        $saisis = $this->stored();

        if ($saisis !== null && $saisis->isComplete()) {
            return $saisis;
        }

        /*
          La VERSION suit l'écran même quand la clé vient du serveur.

          C'est un réglage, pas un identifiant : elle ne participe pas à la
          complétude de la fiche. Sans cette règle, choisir « One Call 4.0 » à
          l'écran restait sans effet dès lors que la clé était posée dans
          `.env.local` — l'appel repartait en 3.0, et l'écran affichait un
          réglage que personne n'appliquait.
        */
        return $saisis === null ? $fallback : new WeatherCredentials(
            $fallback->apiKey,
            $fallback->latitude,
            $fallback->longitude,
            $fallback->place,
            $fallback->autoApply,
            $saisis->apiVersion,
            $fallback->fromScreen,
        );
    }

    /** Ce qui a été saisi à l'écran, ou null si rien ne l'a été. */
    public function stored(): ?WeatherCredentials
    {
        $row = $this->db->fetchAssociative('SELECT * FROM inv_weather_credential WHERE id = ?', [self::ROW_ID]);

        if ($row === false) {
            return null;
        }

        // Une clé illisible — `APP_SECRET` changé depuis, ligne modifiée à la
        // main — rend une chaîne vide plutôt qu'un charabia : la fiche est
        // alors incomplète, et l'écran redemande la saisie au lieu d'envoyer
        // des octets au hasard à un service facturé.
        return new WeatherCredentials(
            $this->box->decrypt($row['api_key'] === null ? null : (string) $row['api_key']) ?? '',
            (float) ($row['latitude'] ?? 0),
            (float) ($row['longitude'] ?? 0),
            (string) ($row['place'] ?? ''),
            (bool) ($row['auto_apply'] ?? false),
            WeatherCredentials::cleanVersion($row['api_version'] ?? null),
            fromScreen: true,
        );
    }

    /**
     * Enregistre — en gardant la clé déjà posée si le champ est laissé vide.
     *
     * C'est la contrepartie d'un champ qu'on n'affiche jamais : sans cette
     * règle, corriger une latitude aurait effacé la clé, que personne ne peut
     * relire pour la retaper.
     *
     * @throws \RuntimeException si le chiffrement n'est pas possible
     */
    public function save(
        ?string $apiKey,
        float $latitude,
        float $longitude,
        string $place,
        bool $autoApply,
        string $apiVersion = WeatherCredentials::VERSION_3,
    ): void {
        $ancien = $this->db->fetchOne('SELECT api_key FROM inv_weather_credential WHERE id = ?', [self::ROW_ID]);

        $chiffre = $ancien === false ? null : (string) $ancien;

        if ($apiKey !== null && trim($apiKey) !== '') {
            $chiffre = $this->box->encrypt(trim($apiKey))
                ?? throw new \RuntimeException('SECRET_BOX_UNAVAILABLE');
        }

        $data = [
            'api_key' => $chiffre,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'place' => trim($place),
            'auto_apply' => $autoApply ? 1 : 0,
            'api_version' => WeatherCredentials::cleanVersion($apiVersion),
            'updated_at' => Store::now(),
        ];

        if ($ancien === false) {
            $this->db->insert('inv_weather_credential', $data + ['id' => self::ROW_ID]);

            return;
        }

        $this->db->update('inv_weather_credential', $data, ['id' => self::ROW_ID]);
    }

    /** Efface la saisie d'écran : la configuration du serveur reprend la main. */
    public function clear(): void
    {
        $this->db->delete('inv_weather_credential', ['id' => self::ROW_ID]);
    }
}
