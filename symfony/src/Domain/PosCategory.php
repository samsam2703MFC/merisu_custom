<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une catégorie telle que la caisse GoPOS la connaît.
 *
 * On n'en garde que ce dont le module a besoin : l'identifiant, le nom, et le
 * fait qu'elle soit active. Couleur, image et traductions restent chez la
 * caisse — les recopier aurait créé une seconde source de vérité pour des
 * informations que cet écran n'affiche pas.
 */
final readonly class PosCategory
{
    public function __construct(
        public string $externalId,
        public string $name,
        public bool $enabled,
    ) {
    }

    /**
     * Lit une ligne de `GET /categories`, ou rend null si elle est inexploitable.
     *
     * @param array<string, mixed> $row
     */
    public static function fromHost(array $row): ?self
    {
        $id = $row['id'] ?? null;
        $nom = is_string($row['name'] ?? null) ? trim($row['name']) : '';

        if (!is_scalar($id) || (string) $id === '' || $nom === '') {
            return null;
        }

        // « DELETED » n'est pas « désactivé » : une catégorie supprimée chez la
        // caisse ne doit pas revenir dans la liste sous prétexte qu'on la
        // recopie. Elle est écartée à la lecture.
        $statut = is_string($row['status'] ?? null) ? strtoupper($row['status']) : 'ENABLED';

        if ($statut === 'DELETED') {
            return null;
        }

        return new self((string) $id, $nom, $statut === 'ENABLED');
    }
}
