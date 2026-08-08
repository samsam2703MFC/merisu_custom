<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * §5.2 — Production à réaliser pour le lendemain matin.
 *
 *   Requis(demain)  = minimum calculé pour le jour de demain,
 *                     À DÉFAUT ParMatrix[produit, jour_de_demain]
 *   Stock_clôture   = comptage CLOSE_2200 validé du jour
 *   À_produire_brut = max(0, Requis(demain) − Stock_clôture)
 *   À_produire      = arrondi( À_produire_brut × (1 + waste_factor), rounding )
 *
 * Le REQUIS a changé de source : il vient désormais de la moyenne de l'écoulé
 * des six derniers mêmes jours de semaine, corrigée du temps attendu (voir
 * `MinimumStock`). Le seuil saisi en administration reste le filet, pour les
 * fiches trop jeunes pour avoir un historique et pour celles dont les relevés
 * sont trop rares. Les matières premières, elles, n'ont que le seuil : elles
 * ne se fabriquent pas et n'entrent pas dans ce plan.
 *
 * Calculé à la validation du stock du soir, puis figé et horodaté.
 */
final class Production
{
    /**
     * Quantité à produire pour UN produit.
     *
     * Cas limites couverts :
     * - clôture ≥ requis → 0, jamais de valeur négative ;
     * - seuil absent → 0 avec avertissement ;
     * - clôture absente → considérée nulle (il faut produire le requis), signalée ;
     * - facteur de perte appliqué APRÈS le max(0, …), jamais sur un besoin nul.
     */
    public static function line(
        Product $product,
        ?float $closingStock,
        ?float $requiredPieces,
        bool $fromHistory = false,
    ): ProductionLine {
        $missingThreshold = $requiredPieces === null;
        $missingClosingStock = $closingStock === null;

        $required = $missingThreshold ? 0.0 : max(0.0, $requiredPieces);
        $closing = $missingClosingStock ? 0.0 : $closingStock;

        $raw = max(0.0, Rounding::clean($required - $closing));

        // Un facteur de perte négatif est une donnée admin aberrante : on l'ignore.
        $waste = is_finite($product->wasteFactor) ? max(0.0, $product->wasteFactor) : 0.0;

        $qty = Rounding::apply($raw * (1 + $waste), $product->roundingStep, $product->roundingMode);

        return new ProductionLine(
            $product->id,
            $product->code,
            $required,
            $closing,
            $raw,
            $qty,
            $missingThreshold,
            $missingClosingStock,
            $fromHistory,
        );
    }

    /**
     * Plan complet pour le lendemain de `$today`.
     *
     * @param list<Product>              $products      produits ACTIFS uniquement
     * @param array<string, float|null>  $closingStocks stocks 22:00 indexés par productId
     * @param list<ParMatrixEntry>       $parMatrix
     * @param array<string, float>       $minimums      minimums déduits de l'historique,
     *                                                  indexés par productId ; ils PRIMENT
     *                                                  sur le seuil saisi
     */
    public static function plan(
        string $today,
        array $products,
        array $closingStocks,
        array $parMatrix,
        string $workstationId,
        array $minimums = [],
    ): ProductionPlanResult {
        $forDate = BusinessDate::next($today);
        $forDayOfWeek = BusinessDate::dayOfWeek($forDate);

        $lines = [];
        $warnings = [];

        foreach ($products as $product) {
            /*
              Ce qui s'ACHÈTE n'entre pas au plan.

              Le mascarpone se commande, la barquette aussi : leur demander une
              quantité « à produire » n'a aucun sens. Et comme aucun des deux
              n'a de seuil dans la matrice, chacun ajoutait en plus un
              avertissement « seuil manquant » qui noyait les vrais.

              Restent les PRÉPARATIONS et les PRODUITS EN VENTE : tous deux se
              fabriquent, tous deux se planifient.

              Le filtre est ici et non dans le service : c'est une règle de
              production, et c'est ici qu'elle se lit avec la formule.
            */
            if ($product->nature->isPurchased()) {
                continue;
            }

            /*
              Le minimum CALCULÉ prime sur le seuil saisi.

              Une composition se fabrique : ce qu'il faut en avoir demain, c'est
              ce qui s'est écoulé les six derniers mêmes jours de semaine,
              corrigé du temps attendu. Le seuil manuel reste le filet — pour
              les fiches trop jeunes pour avoir un historique, et pour celles
              dont les relevés sont trop rares pour qu'une moyenne veuille dire
              quelque chose.

              Prime, et n'additionne pas : les deux répondent à la même question,
              et les cumuler doublerait la production.
            */
            $calcule = $minimums[$product->id] ?? null;
            $depuisHistorique = $calcule !== null;

            $required = $calcule
                ?? self::resolveRequiredPieces($parMatrix, $product->id, $forDayOfWeek, $workstationId);

            $line = self::line($product, $closingStocks[$product->id] ?? null, $required, $depuisHistorique);

            $lines[] = $line;
            if ($line->missingThreshold) {
                $warnings[] = $product->id;
            }
        }

        return new ProductionPlanResult($forDate, $forDayOfWeek, $workstationId, $lines, $warnings);
    }

    /**
     * Seuil applicable : celui du poste s'il existe, sinon le seuil global.
     *
     * Renvoie null si aucun seuil n'est défini — à ne pas confondre avec un seuil
     * volontairement fixé à 0 par l'administrateur.
     *
     * @param list<ParMatrixEntry> $parMatrix
     */
    public static function resolveRequiredPieces(
        array $parMatrix,
        string $productId,
        DayOfWeek $dayOfWeek,
        ?string $workstationId,
    ): ?float {
        $global = null;

        foreach ($parMatrix as $entry) {
            if ($entry->productId !== $productId || $entry->dayOfWeek !== $dayOfWeek) {
                continue;
            }
            if ($workstationId !== null && $entry->workstationId === $workstationId) {
                return $entry->requiredPieces;
            }
            if ($entry->workstationId === null) {
                $global = $entry->requiredPieces;
            }
        }

        return $global;
    }
}
