<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une organisation que la caisse ouvre à une paire d'identifiants.
 *
 * ── Une paire peut en ouvrir PLUSIEURS
 *
 * C'est le point qu'on ne devine pas depuis la documentation : les
 * identifiants GoPOS sont liés à une organisation « au moment où on les
 * génère », ce qui laisse croire qu'il en faut une paire par boutique. Mais
 * `/api/v3/me` rend une LISTE, et une même paire peut porter des droits sur
 * plusieurs organisations.
 *
 * D'où deux montages possibles, et l'écran doit permettre les deux :
 *
 * · une paire par boutique — trois boutiques, trois paires, trois secrets ;
 * · une paire pour le réseau — un secret, et chaque boutique n'apporte que
 *   son numéro d'organisation.
 *
 * Le second est le plus simple à tenir : un secret à faire tourner au lieu de
 * trois. Mais il n'est possible que si la caisse a accordé les droits, et
 * personne ne peut le savoir d'avance — seul `/api/v3/me` le dit.
 */
final readonly class PosOrganization
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $enabled = true,
    ) {
    }

    /**
     * Une ligne de `GET /api/v3/me`.
     *
     * @param array<string, mixed> $row
     */
    public static function fromHost(array $row): ?self
    {
        $id = $row['id'] ?? null;

        if (!is_scalar($id) || (string) $id === '') {
            return null;
        }

        $statut = is_string($row['status'] ?? null) ? strtoupper($row['status']) : 'ENABLED';
        $nom = is_string($row['name'] ?? null) ? trim($row['name']) : '';

        return new self(
            (string) $id,
            // Sans nom, l'identifiant : mieux vaut « 13232 » qu'une ligne vide
            // dans une liste où l'on choisit une boutique.
            $nom !== '' ? $nom : (string) $id,
            $statut !== 'DELETED' && $statut !== 'DISABLED',
        );
    }
}
