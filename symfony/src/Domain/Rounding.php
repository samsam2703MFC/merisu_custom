<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Arrondi paramétrable des quantités à produire (§5.2).
 *
 * Le pas et le mode viennent de la fiche produit : on ne suppose jamais « à la
 * pièce entière », certains produits se comptent au gramme ou au millilitre.
 */
final class Rounding
{
    /**
     * Arrondit `$value` selon le pas et le mode fournis.
     *
     * Un pas invalide (0, négatif) est ignoré : on renvoie la valeur brute plutôt
     * qu'un INF ou un NAN dans un plan de production.
     */
    public static function apply(float $value, float $step, RoundingMode $mode): float
    {
        if (!is_finite($value)) {
            return 0.0;
        }

        if ($mode === RoundingMode::None || !is_finite($step) || $step <= 0) {
            return self::clean($value);
        }

        $ratio = self::clean($value / $step);

        return self::clean(match ($mode) {
            RoundingMode::Ceil => ceil($ratio) * $step,
            RoundingMode::Floor => floor($ratio) * $step,
            RoundingMode::Nearest => round($ratio) * $step,
            RoundingMode::None => $value,
        });
    }

    /** Corrige les artefacts de virgule flottante (0.1 + 0.2 → 0.30000000000000004). */
    public static function clean(float $value): float
    {
        return round($value, 9);
    }
}
