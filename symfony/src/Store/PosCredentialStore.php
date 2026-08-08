<?php

declare(strict_types=1);

namespace Merisu\Inventory\Store;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Domain\PosCredentials;
use Merisu\Inventory\Service\SecretBox;

/**
 * Les identifiants de caisse saisis à l'écran.
 *
 * ── Qui l'emporte, de l'écran ou du serveur ?
 *
 * L'ÉCRAN. C'est le geste le plus récent et le plus délibéré : quelqu'un vient
 * de taper trois valeurs et attend qu'elles s'appliquent. Une variable
 * d'environnement qui les aurait silencieusement recouvertes aurait donné un
 * écran où l'on saisit sans effet, et personne n'aurait su pourquoi.
 *
 * Les variables d'environnement restent le REPLI : elles servent au premier
 * démarrage, à un déploiement automatisé, ou quand on veut interdire la
 * saisie. L'écran dit toujours laquelle des deux sources est en vigueur.
 *
 * ── Le secret est chiffré
 *
 * Avec une clé dérivée d'`APP_SECRET`, qui vit hors de la base. Une base
 * dérobée seule ne livre donc pas de quoi appeler la caisse — même protection
 * que les codes PIN, et pour la même raison.
 */
final readonly class PosCredentialStore
{
    /** Une seule ligne : une installation ne parle qu'à une caisse. */
    private const ROW_ID = 1;

    public function __construct(
        private Connection $db,
        private SecretBox $box,
    ) {
    }

    /**
     * Les identifiants en vigueur : l'écran d'abord, le serveur ensuite.
     *
     * @param PosCredentials $fallback ce que porte l'environnement
     */
    public function effective(PosCredentials $fallback): PosCredentials
    {
        $saisis = $this->stored();

        return $saisis !== null && $saisis->isComplete() ? $saisis : $fallback;
    }

    /** Ce qui a été saisi à l'écran, ou null si rien ne l'a été. */
    public function stored(): ?PosCredentials
    {
        $row = $this->db->fetchAssociative('SELECT * FROM inv_pos_credential WHERE id = ?', [self::ROW_ID]);

        if ($row === false) {
            return null;
        }

        // Un secret illisible — `APP_SECRET` changé depuis, ligne modifiée à la
        // main — rend une chaîne vide plutôt qu'un charabia : la fiche est
        // alors incomplète, et l'écran redemande la saisie au lieu d'envoyer
        // des octets au hasard à la caisse.
        return new PosCredentials(
            (string) ($row['client_id'] ?? ''),
            $this->box->decrypt($row['client_secret'] === null ? null : (string) $row['client_secret']) ?? '',
            (string) ($row['organization_id'] ?? ''),
            (string) ($row['base_url'] ?? ''),
            fromScreen: true,
        );
    }

    /**
     * Enregistre — en gardant le secret déjà posé si le champ est laissé vide.
     *
     * C'est la contrepartie d'un champ qu'on n'affiche jamais : sans cette
     * règle, corriger une faute de frappe dans l'identifiant aurait effacé le
     * secret, que personne ne peut relire pour le retaper.
     *
     * @throws \RuntimeException si le chiffrement n'est pas possible
     */
    public function save(string $clientId, ?string $clientSecret, string $organizationId, string $baseUrl): void
    {
        $ancien = $this->db->fetchOne('SELECT client_secret FROM inv_pos_credential WHERE id = ?', [self::ROW_ID]);

        $chiffre = $ancien === false ? null : (string) $ancien;

        if ($clientSecret !== null && trim($clientSecret) !== '') {
            $chiffre = $this->box->encrypt(trim($clientSecret))
                ?? throw new \RuntimeException('SECRET_BOX_UNAVAILABLE');
        }

        $data = [
            'client_id' => trim($clientId),
            'client_secret' => $chiffre,
            'organization_id' => trim($organizationId),
            'base_url' => trim($baseUrl),
            'updated_at' => Store::now(),
        ];

        if ($ancien === false) {
            $this->db->insert('inv_pos_credential', $data + ['id' => self::ROW_ID]);

            return;
        }

        $this->db->update('inv_pos_credential', $data, ['id' => self::ROW_ID]);
    }

    /** Efface la saisie d'écran : la configuration du serveur reprend la main. */
    public function clear(): void
    {
        $this->db->delete('inv_pos_credential', ['id' => self::ROW_ID]);
    }
}
