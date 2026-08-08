<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\MinimumStock;
use Merisu\Inventory\Domain\NetVariance;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\WeatherKind;
use Merisu\Inventory\Store\Store;

/**
 * Stock minimum déduit de l'historique, jour de semaine par jour de semaine.
 *
 * Le CALCUL vit dans `MinimumStock` ; ce service ne fait que lui apporter
 * l'historique et lui rendre le résultat rangé. C'est la règle de la maison :
 * aucune formule au-dessus du domaine.
 *
 * ⚠️ L'historique est celui de l'ÉCOULÉ — ouverture + production − clôture —
 * c'est-à-dire les ventes ET les pertes. Ce module compte ce qu'il y a dans
 * les bacs, il ne connaît ni les encaissements ni les tickets. Les écrans le
 * disent : appeler cela « ventes » ferait passer la casse pour de la demande.
 */
final class MinimumStockService
{
    public function __construct(private readonly Store $store)
    {
    }

    /**
     * Les minimums de tous les produits, pour un jour de semaine donné.
     *
     * Une seule requête pour les six semaines : produit par produit, la page
     * des seuils en aurait lancé sept par fiche.
     *
     * @param list<Product> $products
     *
     * @return array<string, MinimumStock|null> indexé par identifiant de produit
     */
    public function forDayOfWeek(
        array $products,
        DayOfWeek $dayOfWeek,
        string $today,
        ?string $workstationId,
        WeatherKind $weather,
    ): array {
        return $this->forWeek($products, $today, $workstationId, $weather)[$dayOfWeek->value] ?? [];
    }

    /**
     * Les minimums des sept jours de la semaine, en UNE lecture de l'historique.
     *
     * Sept appels à `forDayOfWeek` auraient lancé sept requêtes sur des
     * fenêtres presque identiques — six semaines chacune, décalées d'un jour.
     * L'écran des seuils affiche les sept colonnes : il les demande donc
     * ensemble.
     *
     * @param list<Product> $products
     *
     * @return array<string, array<string, MinimumStock|null>> [jour][produit]
     */
    public function forWeek(array $products, string $today, ?string $workstationId, WeatherKind $weather): array
    {
        $parJour = [];
        $toutes = [];

        foreach (DayOfWeek::all() as $jour) {
            $parJour[$jour->value] = BusinessDate::lastOccurrences($jour, $today);
            $toutes = array_merge($toutes, $parJour[$jour->value]);
        }

        $ecoule = $this->netByDate($toutes, $workstationId);
        $pct = $this->store->weatherRatios()[$weather->value] ?? 0.0;

        $sortie = [];
        foreach ($parJour as $jour => $dates) {
            $sortie[$jour] = $this->compute($products, $dates, $ecoule, $pct);
        }

        return $sortie;
    }

    /**
     * @param list<Product>                                $products
     * @param list<string>                                 $dates
     * @param array<string, array<string, float|null>>     $ecoule
     *
     * @return array<string, MinimumStock|null>
     */
    private function compute(array $products, array $dates, array $ecoule, float $pct): array
    {
        $sortie = [];

        foreach ($products as $product) {
            // Une matière première ne se fabrique pas : lui calculer un stock
            // minimum de PRODUCTION n'aurait aucun sens. Elle se recommande,
            // et c'est l'approvisionnement qui s'en charge.
            if (!$product->isProduced()) {
                $sortie[$product->id] = null;
                continue;
            }

            $historique = [];
            foreach ($dates as $date) {
                $historique[] = $ecoule[$date][$product->id] ?? null;
            }

            $sortie[$product->id] = MinimumStock::of($historique, $pct, $product);
        }

        return $sortie;
    }

    /**
     * Écoulé par date et par produit, sur les dates demandées.
     *
     * @param list<string> $dates
     *
     * @return array<string, array<string, float|null>>
     */
    private function netByDate(array $dates, ?string $workstationId): array
    {
        if ($dates === []) {
            return [];
        }

        $filtre = ['from' => min($dates), 'to' => max($dates)];

        // Poste absent = toute la boutique. Les seuils de l'écran d'administration
        // sont GLOBAUX : les calculer sur un seul poste sous-estimerait ce qui
        // s'écoule là où il y en a plusieurs.
        if ($workstationId !== null) {
            $filtre['workstationId'] = $workstationId;
        }

        // Une seule requête, bornée à la plus ancienne et la plus récente date :
        // les jours intermédiaires sont lus pour rien, mais c'est quarante et
        // une requêtes de moins et la table est indexée sur la date.
        $sommes = [];
        foreach ($this->store->counts($filtre) as $count) {
            // Sommé sur les postes : ouverture et clôture d'un même produit se
            // comptent bac par bac, et la boutique entière en est le total.
            $cle = &$sommes[$count->date][$count->moment->value][$count->productId];
            $cle = ($cle ?? 0.0) + $count->qty;

            $produit = &$sommes[$count->date]['PRODUCED'][$count->productId];
            $produit = ($produit ?? 0.0) + ($count->producedQty ?? 0.0);
        }
        unset($cle, $produit);

        $sortie = [];
        foreach ($dates as $date) {
            $sortie[$date] = [];

            $ouverture = $sommes[$date][CountMoment::Open0800->value] ?? [];
            $cloture = $sommes[$date][CountMoment::Close2200->value] ?? [];

            foreach (array_keys($ouverture + $cloture) as $productId) {
                // `NetVariance` rend null dès qu'un comptage manque : c'est
                // exactement le « jour écarté » que `MinimumStock` attend.
                $sortie[$date][$productId] = NetVariance::compute(
                    $date,
                    (string) $productId,
                    $workstationId ?? '',
                    $ouverture[$productId] ?? null,
                    $cloture[$productId] ?? null,
                    $sommes[$date]['PRODUCED'][$productId] ?? 0.0,
                )->netConsumption;
            }
        }

        return $sortie;
    }
}
