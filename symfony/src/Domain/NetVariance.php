<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * §5.1 — Écart net du jour.
 *
 *   Consommation nette = Stock initial (08:00) + Production entrée − Stock final (22:00)
 *
 * Ce qui a été écoulé (ventes + pertes) entre l'ouverture et la clôture. Si la
 * production intra-journée n'est pas suivie, la formule retombe naturellement
 * sur « Stock initial − Stock final ».
 */
final class NetVariance
{
    public static function compute(
        string $date,
        string $productId,
        string $workstationId,
        ?float $openingStock,
        ?float $closingStock,
        ?float $producedQty = null,
    ): DailyNet {
        $produced = $producedQty ?? 0.0;
        $partial = $openingStock === null || $closingStock === null;

        // Tant qu'un comptage manque, aucun chiffre n'est affiché : on ne laisse
        // jamais conclure sur des données incomplètes (§6).
        $net = $partial ? null : Rounding::clean($openingStock + $produced - $closingStock);

        return new DailyNet(
            $date,
            $productId,
            $workstationId,
            $openingStock,
            $closingStock,
            $produced,
            $net,
            $partial,
        );
    }

    /**
     * Somme des consommations nettes, jours incomplets exclus.
     *
     * @param list<DailyNet> $nets
     *
     * @return array{total: float, skipped: int, partial: bool}
     */
    public static function sum(array $nets): array
    {
        $total = 0.0;
        $skipped = 0;

        foreach ($nets as $net) {
            if ($net->netConsumption === null) {
                ++$skipped;
                continue;
            }
            $total += $net->netConsumption;
        }

        return [
            'total' => Rounding::clean($total),
            'skipped' => $skipped,
            'partial' => $skipped > 0,
        ];
    }
}
