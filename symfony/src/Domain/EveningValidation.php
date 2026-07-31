<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * §5.3 — Règles de validation du comptage du soir.
 *
 * Le soir ne peut être validé que si TOUS les produits actifs ont un stock saisi
 * (et une photo si l'administrateur l'exige). Une fois validé, les saisies sont
 * verrouillées ; seul un ADMIN peut déverrouiller, avec trace d'audit.
 */
final class EveningValidation
{
    /**
     * Politique photo (question ouverte §10), pilotée par deux paramètres admin :
     * - `$photoRequired = false`                → aucune photo exigée ;
     * - `$photoRequired && $photoPerProduct`    → une photo par produit actif ;
     * - `$photoRequired && !$photoPerProduct`   → au moins une photo, globale.
     *
     * @param list<Product>            $activeProducts
     * @param array<string,float|null> $quantities         quantités saisies, par produit
     * @param array<string,int>        $photoCountByProduct
     */
    public static function validate(
        array $activeProducts,
        array $quantities,
        array $photoCountByProduct,
        int $globalPhotoCount,
        bool $photoRequired,
        bool $photoPerProduct,
        bool $alreadyValidated,
    ): ValidationResult {
        $issues = [];

        if ($alreadyValidated) {
            $issues[] = new ValidationIssue('ALREADY_VALIDATED');
        }

        foreach ($activeProducts as $product) {
            $qty = $quantities[$product->id] ?? null;

            if ($qty === null || !is_finite($qty)) {
                $issues[] = new ValidationIssue('MISSING_QTY', $product->id);
            } elseif ($qty < 0) {
                $issues[] = new ValidationIssue('NEGATIVE_QTY', $product->id);
            }

            if ($photoRequired && $photoPerProduct && ($photoCountByProduct[$product->id] ?? 0) < 1) {
                $issues[] = new ValidationIssue('MISSING_PHOTO', $product->id);
            }
        }

        if ($photoRequired && !$photoPerProduct) {
            // Une photo rattachée à un produit compte comme preuve globale.
            $attached = array_sum($photoCountByProduct);
            if ($globalPhotoCount + $attached < 1) {
                $issues[] = new ValidationIssue('MISSING_GLOBAL_PHOTO');
            }
        }

        return new ValidationResult($issues === [], $issues);
    }

    /** Une saisie verrouillée n'est modifiable que par un ADMIN. */
    public static function canEdit(bool $validated, Role $role): bool
    {
        return !$validated || $role->isAdmin();
    }
}
