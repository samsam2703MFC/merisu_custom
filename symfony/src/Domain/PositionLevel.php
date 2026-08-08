<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Un niveau à l'intérieur d'un poste RH — « débutant », « confirmé », « référent ».
 *
 * `order` porte la progression, et l'hôte l'expose sous le même nom
 * (`level_order`). Deux niveaux de même rang ne sont pas interdits : une
 * boutique peut vouloir deux spécialités de même échelon, et refuser l'égalité
 * aurait obligé à inventer une hiérarchie qui n'existe pas.
 */
final readonly class PositionLevel
{
    public function __construct(
        public string $id,
        public string $positionId,
        public string $name,
        public ?string $description,
        public int $order,
    ) {
    }
}
