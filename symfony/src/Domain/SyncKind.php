<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qu'une remontée transporte.
 *
 * Deux natures, parce que TF Buddy les reçoit à deux endroits différents :
 * l'inventaire d'un produit se pose ligne par ligne
 * (`PATCH /shops/{id}/products/{id}/inventory`), tandis qu'un relevé de
 * matières s'envoie d'un bloc (`POST /shops/{id}/materials/stocktakings`).
 *
 * Les valeurs sont stockées en base : elles ne changent pas.
 */
enum SyncKind: string
{
    /** Stock d'un produit fini, à une date et un moment donnés. */
    case ProductInventory = 'PRODUCT_INVENTORY';

    /** Relevé de matières premières — un stocktaking complet. */
    case MaterialStocktaking = 'MATERIAL_STOCKTAKING';

    public static function fromLoose(mixed $value): ?self
    {
        return self::tryFrom(is_scalar($value) ? strtoupper((string) $value) : '');
    }
}
