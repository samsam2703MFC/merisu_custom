<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Manière de compter un produit.
 *
 * Certains produits se comptent à l'unité — on en voit douze, on saisit douze.
 * D'autres vivent dans des bacs, des seaux ou des bouteilles, et le dernier
 * contenant est presque toujours entamé. Demander « 2,75 » au vendeur, c'est
 * lui demander une division mentale devant un bac aux trois quarts plein.
 *
 * Le mode conteneur lui fait saisir ce qu'il voit : deux bacs pleins, et le
 * troisième aux trois quarts.
 */
enum CountMode: string
{
    case Pieces = 'PIECES';
    case Container = 'CONTAINER';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Pieces, self::Container];
    }

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtoupper(trim($value)));
    }

    public function isContainer(): bool
    {
        return $this === self::Container;
    }
}
