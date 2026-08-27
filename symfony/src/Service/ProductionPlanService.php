<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductionForecast;
use Merisu\Inventory\Store\Store;

/**
 * Assemble le plan de production : ce qu'il faudra produire, et de quoi.
 *
 * ── Le calcul vit dans le DOMAINE, pas ici
 *
 * `ProductionForecast` porte la règle — base du jour de semaine, correction
 * météo, objectif — et se prouve sans base de données. Ce service ne fait que
 * l'alimenter : les ventes observées, le temps annoncé, les taux réglés, et le
 * périmètre de boutique. C'est la même séparation que pour le contrôle de
 * réalité, et pour la même raison : une règle de calcul enfouie dans un
 * contrôleur ne se vérifie qu'en ouvrant un navigateur.
 *
 * ── Six semaines EN ARRIÈRE, sept jours EN AVANT
 *
 * La fenêtre d'observation ne se règle pas à l'écran : c'est une constante du
 * domaine, et la laisser bouger ferait varier la prévision sans qu'on sache
 * plus si c'est la boutique qui change ou le réglage.
 */
final readonly class ProductionPlanService
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @param list<string>|null $shopCodes boutiques à observer, `null` = pas de filtre
     *
     * @return array{
     *   forecast: ProductionForecast,
     *   from: string, to: string,
     *   observedFrom: string, observedTo: string,
     *   materials: array<string, float>,
     *   materialNames: array<string, Product>,
     *   withoutRecipe: list<string>,
     *   hasSales: bool, hasWeather: bool
     * }
     */
    public function plan(
        string $today,
        int $daysAhead,
        ?array $shopCodes,
        float $targetPercent,
    ): array {
        // ── Ce qu'on a VU : six semaines de ventes, jusqu'à hier ────────────
        //
        // Jusqu'à HIER et non jusqu'à aujourd'hui : la journée en cours n'est
        // pas finie, et sa demi-journée de ventes tirerait vers le bas la
        // moyenne du jour de semaine correspondant, chaque jour de l'année.
        $observedTo = BusinessDate::previous($today);
        $observedFrom = BusinessDate::addDays($observedTo, -(ProductionForecast::WEEKS * 7) + 1);

        $ventesParDate = [];
        $noms = [];

        foreach ($this->store->sales($observedFrom, $observedTo, $shopCodes) as $vente) {
            $ventesParDate[$vente->date][$vente->externalId] =
                ($ventesParDate[$vente->date][$vente->externalId] ?? 0.0) + $vente->quantity;

            if ($vente->name !== '') {
                $noms[$vente->externalId] = $vente->name;
            }
        }

        // ── Ce qui est ANNONCÉ : les jours à planifier ──────────────────────
        $dates = [];
        for ($i = 0; $i < max(1, $daysAhead); ++$i) {
            $dates[] = BusinessDate::addDays($today, $i);
        }

        /*
          Les références qu'on FABRIQUE.

          Le catalogue tranche : une fiche qui se produit entre au plan, un
          emballage ou une matière non. Une référence de caisse rattachée à
          aucune fiche — un frais de service, une livraison — n'y entre pas
          davantage : on ne planifie pas la production de ce qu'on ne connaît
          pas, et le bon demandait de fabriquer des livraisons.
        */
        $produites = [];
        foreach ($this->store->products() as $produit) {
            $ref = trim((string) ($produit->recipeRef ?? ''));

            if ($ref !== '' && $produit->nature->isProduced()) {
                $produites[] = $ref;
            }
        }

        $prevision = $this->store->weatherForecast($today);
        $temps = [];
        foreach ($prevision->days as $jour) {
            $temps[$jour->date] = $jour;
        }

        $forecast = ProductionForecast::build(
            $ventesParDate,
            $temps,
            $this->store->temperatureRatios(),
            $this->store->weatherRatios(),
            $dates,
            $targetPercent,
            $noms,
            // Pas de catalogue exploitable : on ne filtre pas. Mieux vaut un
            // plan trop large qu'un écran vide qu'on ne saurait pas expliquer.
            $produites === [] ? null : $produites,
        );

        // ── Ce qu'il faudra SORTIR des bacs ─────────────────────────────────
        $produits = [];
        $refVersId = [];
        foreach ($this->store->products() as $produit) {
            $produits[$produit->id] = $produit;

            $ref = trim((string) ($produit->recipeRef ?? ''));
            if ($ref !== '') {
                $refVersId[$ref] = $produit->id;
            }
        }

        $nomenclatures = [];
        foreach ($this->store->recipeLines() as $ligne) {
            $nomenclatures[$ligne->productId][$ligne->materialId] = $ligne->qtyPerUnit;
        }

        return [
            'forecast' => $forecast,
            'from' => $dates[0],
            'to' => $dates[\count($dates) - 1],
            'observedFrom' => $observedFrom,
            'observedTo' => $observedTo,
            'materials' => $forecast->materials($forecast->totalPieces, $nomenclatures, $refVersId),
            'materialNames' => $produits,
            'withoutRecipe' => $forecast->productsWithoutRecipe($nomenclatures, $refVersId),
            'hasSales' => $ventesParDate !== [],
            'hasWeather' => $temps !== [],
        ];
    }
}
