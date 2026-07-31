<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Mode d'arrondi appliqué à la quantité à produire. */
enum RoundingMode: string
{
    /** Multiple supérieur — défaut métier : on ne produit jamais sous le besoin. */
    case Ceil = 'CEIL';
    case Floor = 'FLOOR';
    case Nearest = 'NEAREST';
    /** Aucun arrondi : pour les produits comptés au gramme ou au millilitre. */
    case None = 'NONE';
}
