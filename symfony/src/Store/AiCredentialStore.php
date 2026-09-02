<?php

declare(strict_types=1);

namespace Merisu\Inventory\Store;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Domain\AiCredentials;
use Merisu\Inventory\Service\SecretBox;

/**
 * La clé de traduction assistée, saisie en administration.
 *
 * Même dispositif que les identifiants de caisse ou de météo : une seule ligne,
 * l'écran l'emporte sur le serveur, la clé est chiffrée avec une clé dérivée
 * d'`APP_SECRET` qui vit hors de la base. Une réinstallation qui repart sur un
 * `.env.local` neuf efface la variable d'environnement ; la clé saisie à
 * l'écran, elle, reste en base et l'assistant continue de traduire.
 */
final readonly class AiCredentialStore
{
    /** Une seule ligne : une installation traduit avec une seule clé. */
    private const ROW_ID = 1;

    public function __construct(
        private Connection $db,
        private SecretBox $box,
    ) {
    }

    /**
     * Les réglages en vigueur : l'écran d'abord, le serveur ensuite.
     *
     * @param AiCredentials $fallback ce que porte l'environnement
     */
    public function effective(AiCredentials $fallback): AiCredentials
    {
        $saisis = $this->stored();

        if ($saisis !== null && $saisis->isComplete()) {
            return $saisis;
        }

        /*
          Le MODÈLE suit l'écran même quand la clé vient du serveur.

          C'est un réglage, pas un identifiant : il ne participe pas à la
          complétude de la fiche. Sans cette règle, choisir un modèle à l'écran
          restait sans effet dès lors que la clé venait de `.env.local`.
        */
        return $saisis === null ? $fallback : new AiCredentials(
            $fallback->apiKey,
            $saisis->model,
            $fallback->fromScreen,
        );
    }

    /** Ce qui a été saisi à l'écran, ou null si rien ne l'a été. */
    public function stored(): ?AiCredentials
    {
        $row = $this->db->fetchAssociative('SELECT * FROM inv_ai_credential WHERE id = ?', [self::ROW_ID]);

        if ($row === false) {
            return null;
        }

        // Une clé illisible — `APP_SECRET` changé depuis, ligne modifiée à la
        // main — rend une chaîne vide plutôt qu'un charabia : la fiche est
        // alors incomplète, et l'écran redemande la saisie au lieu d'envoyer
        // des octets au hasard à un service facturé.
        return new AiCredentials(
            $this->box->decrypt($row['api_key'] === null ? null : (string) $row['api_key']) ?? '',
            AiCredentials::cleanModel($row['model'] ?? null),
            fromScreen: true,
        );
    }

    /**
     * Enregistre — en gardant la clé déjà posée si le champ est laissé vide.
     *
     * C'est la contrepartie d'un champ qu'on n'affiche jamais : sans cette
     * règle, corriger le modèle aurait effacé la clé, que personne ne peut
     * relire pour la retaper.
     *
     * @throws \RuntimeException si le chiffrement n'est pas possible
     */
    public function save(?string $apiKey, string $model = AiCredentials::DEFAULT_MODEL): void
    {
        $ancien = $this->db->fetchOne('SELECT api_key FROM inv_ai_credential WHERE id = ?', [self::ROW_ID]);

        $chiffre = $ancien === false ? null : (string) $ancien;

        if ($apiKey !== null && trim($apiKey) !== '') {
            $chiffre = $this->box->encrypt(trim($apiKey))
                ?? throw new \RuntimeException('SECRET_BOX_UNAVAILABLE');
        }

        $data = [
            'api_key' => $chiffre,
            'model' => AiCredentials::cleanModel($model),
            'updated_at' => Store::now(),
        ];

        if ($ancien === false) {
            $this->db->insert('inv_ai_credential', $data + ['id' => self::ROW_ID]);

            return;
        }

        $this->db->update('inv_ai_credential', $data, ['id' => self::ROW_ID]);
    }

    /** Efface la saisie d'écran : la configuration du serveur reprend la main. */
    public function clear(): void
    {
        $this->db->delete('inv_ai_credential', ['id' => self::ROW_ID]);
    }
}
