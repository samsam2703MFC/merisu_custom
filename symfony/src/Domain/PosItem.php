<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Un article de la caisse GoPOS.
 *
 * ── Seuls les PRODUITS entrent
 *
 * La caisse distingue `PRODUCT`, `MODIFIER` et `PACKAGE`. Un modificateur
 * (« supplément cacao ») n'est pas une ligne qu'on compte au frigo : le faire
 * entrer aurait rempli l'inventaire de lignes sans stock, que le vendeur
 * aurait dû compter chaque matin.
 *
 * `reference_id` et `sku` sont conservés parce que ce sont eux, et non le nom,
 * qui relient l'article à la fiche produit locale — un nom se retape, un
 * identifiant non.
 */
final readonly class PosItem
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $sku,
        public ?string $categoryId,
        public ?string $categoryName,
        public bool $enabled,
    ) {
    }

    /**
     * Lit une ligne de `GET /items`, ou rend null si elle n'a pas sa place ici.
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

        $type = is_string($row['type'] ?? null) ? strtoupper($row['type']) : 'PRODUCT';
        $statut = is_string($row['status'] ?? null) ? strtoupper($row['status']) : 'ENABLED';

        if ($type !== 'PRODUCT' || $statut === 'DELETED') {
            return null;
        }

        $categorie = is_array($row['category'] ?? null) ? $row['category'] : [];
        $categorieId = $row['category_id'] ?? ($categorie['id'] ?? null);
        $categorieNom = is_string($categorie['name'] ?? null) ? trim($categorie['name']) : '';

        return new self(
            (string) $id,
            $nom,
            self::texteOuNull($row['sku'] ?? null) ?? self::texteOuNull($row['reference_id'] ?? null),
            is_scalar($categorieId) && (string) $categorieId !== '' ? (string) $categorieId : null,
            $categorieNom !== '' ? $categorieNom : null,
            $statut === 'ENABLED',
        );
    }

    private static function texteOuNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $texte = trim((string) $value);

        return $texte === '' ? null : $texte;
    }
}
