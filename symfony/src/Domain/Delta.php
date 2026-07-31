<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * §6 — Delta technique : recettes ↔ consommation réelle de matières.
 *
 *   Consommation théorique(matière) = Σ ( pièces produites × qty_par_unité )
 *   Consommation réelle(matière)    = variation réelle de stock sur la période
 *   Delta                           = réel − théorique
 *   Delta %                         = Delta / théorique
 *
 * Résultats INDICATIFS tant que les données sont incomplètes : chaque ligne
 * porte un drapeau `partial` que l'interface doit afficher.
 */
final class Delta
{
    /**
     * Consommation théorique par matière.
     *
     * @param array<string, float>              $producedByProduct pièces produites, par produit
     * @param array<string, array<string,float>> $recipes          [productId][materialId] => qtyPerUnit
     *
     * @return array{byMaterial: array<string,float>, productsWithoutRecipe: list<string>}
     */
    public static function theoreticalConsumption(array $producedByProduct, array $recipes): array
    {
        $byMaterial = [];
        $withoutRecipe = [];

        foreach ($producedByProduct as $productId => $producedQty) {
            $recipe = $recipes[$productId] ?? [];

            if ($recipe === []) {
                // Une recette absente n'invalide pas le rapport : elle le rend partiel.
                if (abs($producedQty) > 0) {
                    $withoutRecipe[] = (string) $productId;
                }
                continue;
            }

            foreach ($recipe as $materialId => $qtyPerUnit) {
                $current = $byMaterial[$materialId] ?? 0.0;
                $byMaterial[$materialId] = Rounding::clean($current + $producedQty * $qtyPerUnit);
            }
        }

        return ['byMaterial' => $byMaterial, 'productsWithoutRecipe' => $withoutRecipe];
    }

    /**
     * Delta par matière, avec signalement des dépassements de tolérance.
     *
     * Cas limites :
     * - théorique = 0 et réel ≠ 0 → delta connu, pourcentage null (division par
     *   zéro), marqué hors tolérance : consommer sans production théorique est
     *   une dérive ;
     * - réel inconnu → delta et pourcentage null, `partial`, jamais hors tolérance.
     *
     * @param array<string,float>       $theoreticalByMaterial
     * @param array<string,float|null>  $realByMaterial
     *
     * @return list<MaterialDelta>
     */
    public static function materialDeltas(
        array $theoreticalByMaterial,
        array $realByMaterial,
        float $tolerance,
    ): array {
        $tolerance = is_finite($tolerance) ? abs($tolerance) : 0.0;

        $materialIds = array_unique([
            ...array_keys($theoreticalByMaterial),
            ...array_keys($realByMaterial),
        ]);
        sort($materialIds);

        $result = [];

        foreach ($materialIds as $materialId) {
            $theoretical = Rounding::clean($theoreticalByMaterial[$materialId] ?? 0.0);
            $real = $realByMaterial[$materialId] ?? null;

            if ($real === null || !is_finite($real)) {
                $result[] = new MaterialDelta($materialId, $theoretical, null, null, null, false, true);
                continue;
            }

            $real = Rounding::clean($real);
            $delta = Rounding::clean($real - $theoretical);
            $deltaPct = $theoretical === 0.0 ? null : Rounding::clean($delta / $theoretical);

            $outOfTolerance = $deltaPct === null
                ? $delta !== 0.0
                : abs($deltaPct) > $tolerance;

            $result[] = new MaterialDelta($materialId, $theoretical, $real, $delta, $deltaPct, $outOfTolerance, false);
        }

        return $result;
    }

    /**
     * Synthèse pour l'entête de l'écran admin.
     *
     * @param list<MaterialDelta> $deltas
     *
     * @return array{materials: int, outOfTolerance: int, partial: bool}
     */
    public static function summarize(array $deltas): array
    {
        $outOfTolerance = 0;
        $partial = false;

        foreach ($deltas as $delta) {
            if ($delta->outOfTolerance) {
                ++$outOfTolerance;
            }
            if ($delta->partial) {
                $partial = true;
            }
        }

        return ['materials' => \count($deltas), 'outOfTolerance' => $outOfTolerance, 'partial' => $partial];
    }
}
