<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\FoodCostCalculator;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\RealityCheck;
use Merisu\Inventory\Store\Store;

/**
 * Assemble le contrôle de réalité : coût théorique déduit des ventes, coût réel
 * lu sur la consommation, jour par jour, puis roulé en semaines et en mois.
 *
 * ── Une seule définition du « réel »
 *
 * Le coût réel vient des MOUVEMENTS DE MATIÈRE — la même source que le delta
 * technique. Le tirer d'ailleurs (des comptages, d'une autre saisie) aurait
 * donné deux chiffres de consommation réelle dans l'application, l'un ici,
 * l'autre là, sans que rien ne dise lequel croire. Un tableau de bord qui se
 * contredit lui-même est pire que pas de tableau.
 *
 * ── Le théorique descend jusqu'aux matières achetées
 *
 * Chaque vente est rapprochée de sa fiche produit par la référence caisse, et
 * la fiche est chiffrée par le calculateur de coût, qui descend la recette
 * jusqu'aux matières qui ont un prix. C'est le même coût que montre l'écran
 * Receptury : la jauge et la fiche ne peuvent pas annoncer deux food costs.
 */
final readonly class RealityService
{
    public function __construct(private Store $store)
    {
    }

    /**
     * @param list<string>|null $shopCodes      boutiques pour les ventes, `null` = pas de filtre
     * @param list<string>|null $workstationIds postes pour la consommation, `null` = pas de filtre
     *
     * @return array{
     *   from: string, to: string,
     *   overall: RealityCheck,
     *   days: list<array{date: string, check: RealityCheck}>,
     *   weeks: list<array{key: string, label: string, check: RealityCheck}>,
     *   months: list<array{key: string, label: string, check: RealityCheck}>,
     *   theoretical: float, real: float|null,
     *   theoreticalFoodCost: float|null, realFoodCost: float|null,
     *   theoreticalAll: float, foodCostAll: float|null,
     *   hasRecipes: bool, hasReal: bool, daysMissingReal: int
     * }
     */
    public function forPeriod(
        string $from,
        string $to,
        ?array $shopCodes,
        ?array $workstationIds,
        float $tolerance,
    ): array {
        $couts = $this->unitCosts();

        // Théorique : ce que les ventes AURAIENT DÛ coûter en matière, par jour.
        $theoParJour = [];
        $recetteParJour = [];
        foreach ($this->store->sales($from, $to, $shopCodes) as $vente) {
            $cout = $couts['byRef'][$vente->externalId] ?? null;
            if ($cout !== null) {
                $theoParJour[$vente->date] = ($theoParJour[$vente->date] ?? 0.0) + $vente->quantity * $cout;
            }
            $recetteParJour[$vente->date] = ($recetteParJour[$vente->date] ?? 0.0) + $vente->revenue;
        }

        // Réel : ce qui a réellement quitté les bacs, valorisé, par jour.
        $reelParJour = [];
        $jourAvecReel = [];
        foreach ($this->store->materialMovements($from, $to, $workstationIds) as $mouvement) {
            $prix = $couts['byId'][$mouvement->materialId] ?? 0.0;
            $reelParJour[$mouvement->date] = ($reelParJour[$mouvement->date] ?? 0.0) + $mouvement->realQty * $prix;
            $jourAvecReel[$mouvement->date] = true;
        }

        $recetteRelevee = 0.0;
        foreach ($jourAvecReel as $date => $_) {
            $recetteRelevee += $recetteParJour[$date] ?? 0.0;
        }

        $jours = [];
        $manqueReel = 0;
        foreach (BusinessDate::range($from, $to) as $date) {
            $theo = $theoParJour[$date] ?? 0.0;
            $aReel = isset($jourAvecReel[$date]);
            $reel = $aReel ? ($reelParJour[$date] ?? 0.0) : null;

            // Un jour SANS vente ni consommation n'est pas un trou de données :
            // la boutique était fermée, ou rien ne s'est vendu. Il ne compte
            // pas comme un relevé manquant, sans quoi tout dimanche gonflerait
            // l'alerte d'incomplétude.
            if (!$aReel && $theo > 0.0) {
                ++$manqueReel;
            }

            $jours[] = ['date' => $date, 'check' => RealityCheck::of($theo, $reel, $tolerance)];
        }

        $overall = $this->agrege($theoParJour, $reelParJour, $jourAvecReel, $tolerance);

        return [
            'from' => $from,
            'to' => $to,
            'overall' => $overall,
            'days' => $jours,
            'weeks' => $this->buckets($from, $to, $theoParJour, $reelParJour, $jourAvecReel, $tolerance, 'week'),
            'months' => $this->buckets($from, $to, $theoParJour, $reelParJour, $jourAvecReel, $tolerance, 'month'),

            /*
              Les tuiles de tête sont COHÉRENTES entre elles.

              Théorique, réel et écart portent tous sur les MÊMES jours — ceux
              qu'on a relevés —, sans quoi l'on afficherait un théorique de tout
              le mois, un réel de trois jours et un écart calculé sur ces trois
              seuls : « 1 123 → 1 048, écart +144 » ne veut rien dire, la baisse
              apparente et l'écart annoncé se contredisent.
            */
            'theoretical' => $overall->theoretical,
            'real' => $overall->real,
            'theoreticalFoodCost' => self::foodCostRatio($overall->theoretical, $recetteRelevee),
            'realFoodCost' => $overall->real === null ? null : self::foodCostRatio($overall->real, $recetteRelevee),

            /*
              Le théorique de TOUTES les ventes, à part.

              Il répond à une autre question — « ce que tout ce qui s'est vendu
              aurait dû coûter » — indépendante de ce qu'on a mesuré. Sans aucun
              relevé, c'est le seul chiffre disponible, et il mérite d'être vu ;
              on ne le mêle pas aux tuiles de comparaison pour autant.
            */
            'theoreticalAll' => array_sum($theoParJour),
            'foodCostAll' => self::foodCostRatio(array_sum($theoParJour), array_sum($recetteParJour)),

            'hasRecipes' => $couts['byRef'] !== [],
            'hasReal' => $jourAvecReel !== [],
            'daysMissingReal' => $manqueReel,
        ];
    }

    /**
     * Un contrôle roulé sur toute une plage : théorique sommé, réel sommé sur
     * les seuls jours où l'on a relevé.
     *
     * @param array<string,float> $theoParJour
     * @param array<string,float> $reelParJour
     * @param array<string,bool>  $jourAvecReel
     */
    private function agrege(array $theoParJour, array $reelParJour, array $jourAvecReel, float $tolerance): RealityCheck
    {
        if ($jourAvecReel === []) {
            return RealityCheck::of(0.0, null, $tolerance);
        }

        // Théorique ET réel sur les MÊMES jours — ceux qui ont été relevés.
        // Comparer un théorique de tout le mois à un réel de trois jours
        // afficherait une chute énorme qui ne dit rien : on ne mesure une
        // dérive qu'entre deux chiffres qui couvrent les mêmes journées.
        $theo = 0.0;
        $reel = 0.0;
        foreach ($jourAvecReel as $date => $_) {
            $theo += $theoParJour[$date] ?? 0.0;
            $reel += $reelParJour[$date] ?? 0.0;
        }

        return RealityCheck::of($theo, $reel, $tolerance);
    }

    /**
     * Roule les jours en semaines (clé ISO `o-Www`) ou en mois (`Y-m`).
     *
     * @param array<string,float> $theoParJour
     * @param array<string,float> $reelParJour
     * @param array<string,bool>  $jourAvecReel
     *
     * @return list<array{key: string, label: string, check: RealityCheck}>
     */
    private function buckets(
        string $from,
        string $to,
        array $theoParJour,
        array $reelParJour,
        array $jourAvecReel,
        float $tolerance,
        string $grain,
    ): array {
        $theo = [];
        $reel = [];
        $aReel = [];
        $label = [];
        $ordre = [];

        foreach (BusinessDate::range($from, $to) as $date) {
            // Un jour non relevé n'ouvre pas de seau : les onglets semaine et
            // mois COMPARENT réel et théorique, et un seau sans relevé n'aurait
            // rien à comparer — il afficherait « 0 » là où l'onglet Jour, lui,
            // montre le théorique de la journée. La comparaison ne porte que
            // sur ce qu'on a mesuré ; le détail non mesuré reste au jour.
            if (!isset($jourAvecReel[$date])) {
                continue;
            }

            [$cle, $lib] = self::bucketKey($date, $grain);

            if (!isset($ordre[$cle])) {
                $ordre[$cle] = true;
                $label[$cle] = $lib;
            }

            $theo[$cle] = ($theo[$cle] ?? 0.0) + ($theoParJour[$date] ?? 0.0);
            $reel[$cle] = ($reel[$cle] ?? 0.0) + ($reelParJour[$date] ?? 0.0);
            $aReel[$cle] = true;
        }

        $sortie = [];
        foreach (array_keys($ordre) as $cle) {
            $sortie[] = [
                'key' => $cle,
                'label' => $label[$cle],
                'check' => RealityCheck::of(
                    $theo[$cle] ?? 0.0,
                    isset($aReel[$cle]) ? ($reel[$cle] ?? 0.0) : null,
                    $tolerance,
                ),
            ];
        }

        return $sortie;
    }

    /** @return array{0: string, 1: string} clé triable, libellé affiché */
    private static function bucketKey(string $date, string $grain): array
    {
        $d = new \DateTimeImmutable($date);

        if ($grain === 'month') {
            return [$d->format('Y-m'), $d->format('Y-m')];
        }

        // Semaine ISO : `o` est l'année ISO (celle du jeudi de la semaine), pas
        // l'année civile — sans quoi la semaine à cheval sur le 1er janvier se
        // scinderait en deux, l'une en décembre, l'autre en janvier.
        return [$d->format('o-\WW'), $d->format('o-\WW')];
    }

    /**
     * Le food cost en pourcentage : coût matière rapporté à la recette.
     *
     * `null` sans recette encaissée : un food cost divisé par zéro n'est pas
     * « 0 % », il n'existe pas.
     */
    private static function foodCostRatio(float $cost, float $revenue): ?float
    {
        return $revenue > 0.0 ? round($cost / $revenue * 100, 1) : null;
    }

    /**
     * Coût unitaire de chaque produit : par identifiant, et par référence
     * caisse pour retrouver la vente.
     *
     * @return array{byId: array<string,float>, byRef: array<string,float>}
     */
    private function unitCosts(): array
    {
        $produits = [];
        foreach ($this->store->products() as $produit) {
            $produits[$produit->id] = $produit;
        }

        $nomenclatures = [];
        foreach ($this->store->recipeLines() as $ligne) {
            $nomenclatures[$ligne->productId][$ligne->materialId] = $ligne->qtyPerUnit;
        }

        $calculateur = new FoodCostCalculator($nomenclatures, $produits);

        $byId = [];
        $byRef = [];
        foreach ($produits as $id => $produit) {
            $total = $calculateur->costOf($id)->total();
            $byId[$id] = $total;

            $ref = trim((string) ($produit->recipeRef ?? ''));
            if ($ref !== '' && $total > 0.0) {
                $byRef[$ref] = $total;
            }
        }

        return ['byId' => $byId, 'byRef' => $byRef];
    }
}
