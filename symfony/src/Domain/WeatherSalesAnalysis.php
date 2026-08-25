<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce que le temps fait aux ventes.
 *
 * Croise le JOURNAL météo — le temps qu'il a fait — avec les ventes relevées à
 * la caisse, jour par jour, et rend pour chaque condition l'écart à la journée
 * ordinaire. C'est cet écart, et lui seul, qui a un sens dans un stock
 * minimum : « +19 % au-dessus de 25 °C » se reporte, « 344 par jour » ne se
 * reporte nulle part.
 *
 * ── Pourquoi la moyenne des JOURS et non le total
 *
 * Une tranche de température rare compte peu de jours. Comparer son total à
 * celui d'une tranche courante ne compare que le nombre de jours : la tranche
 * 18–25, qui couvre la moitié de l'été, l'emporterait toujours. On compare
 * donc des journées à des journées.
 *
 * ── Pourquoi certains écarts sont ABSENTS
 *
 * Sous `MIN_DAYS` journées observées, aucun pourcentage n'est rendu. Trois
 * jours au-dessus de 30 °C, dont un 15 août, ne mesurent pas la chaleur : ils
 * mesurent le 15 août. Un chiffre tiré de là, une fois posé dans les seuils,
 * n'a plus rien qui rappelle sur quoi il reposait — et il ferait produire pour
 * de bon. Mieux vaut une case vide, que l'écran assume, qu'une valeur que
 * personne ne pourra plus mettre en doute.
 *
 * ── Ce que cette classe ne fait PAS
 *
 * Elle ne dit pas que le temps CAUSE la vente. Août est chaud et août est
 * touristique ; l'écart mesuré porte les deux, et rien ici ne les sépare.
 * C'est une aide au réglage, pas une démonstration — l'écran le dit, parce
 * qu'un tableau de pourcentages ne le dit pas de lui-même.
 */
final readonly class WeatherSalesAnalysis
{
    /**
     * Journées minimales pour qu'une condition rende un écart.
     *
     * Cinq : en dessous, une seule journée hors norme déplace le résultat de
     * plus que l'effet qu'on cherche à mesurer.
     */
    public const MIN_DAYS = 5;

    /**
     * @param list<WeatherSalesRow> $rows
     * @param float                 $reference unités vendues une journée ordinaire
     */
    private function __construct(
        public array $rows,
        public float $reference,
        public int $observedDays,
    ) {
    }

    /**
     * Croise les ventes et les clés de regroupement.
     *
     * Les clés sont fournies par l'appelant — tranche de température, ciel,
     * jour de semaine — plutôt que déduites ici : le regroupement est une
     * question d'écran, le calcul est une question d'arithmétique, et les
     * mêmes trois lignes de moyenne servent les trois cas.
     *
     * @param array<string, array{units: float, revenue: float}> $salesByDate
     * @param array<string, string>                              $keyByDate   date => clé
     * @param list<string>                                       $order       ordre d'affichage
     */
    public static function build(array $salesByDate, array $keyByDate, array $order): self
    {
        $unites = [];
        $recettes = [];
        $jours = [];

        $totalUnites = 0.0;
        $totalJours = 0;

        foreach ($salesByDate as $date => $totaux) {
            $cle = $keyByDate[$date] ?? null;

            // Une journée sans météo au journal n'entre NULLE PART, pas même
            // dans la référence : la comparer à des journées documentées
            // fausserait les deux côtés de l'écart.
            if ($cle === null) {
                continue;
            }

            $unites[$cle] = ($unites[$cle] ?? 0.0) + $totaux['units'];
            $recettes[$cle] = ($recettes[$cle] ?? 0.0) + $totaux['revenue'];
            $jours[$cle] = ($jours[$cle] ?? 0) + 1;

            $totalUnites += $totaux['units'];
            ++$totalJours;
        }

        $reference = $totalJours > 0 ? $totalUnites / $totalJours : 0.0;

        $lignes = [];

        foreach ($order as $cle) {
            $n = $jours[$cle] ?? 0;

            $moyenne = $n > 0 ? $unites[$cle] / $n : 0.0;

            $lignes[] = new WeatherSalesRow(
                $cle,
                $n,
                round($moyenne, 1),
                $n > 0 ? round($recettes[$cle] / $n, 2) : 0.0,
                // Pas d'écart sans matière, et pas d'écart sans référence :
                // une division par une moyenne nulle rendrait l'infini.
                $n >= self::MIN_DAYS && $reference > 0.0
                    ? round((($moyenne - $reference) / $reference) * 100, 1)
                    : null,
            );
        }

        return new self($lignes, round($reference, 1), $totalJours);
    }

    /** Les écarts exploitables, prêts à être reportés. @return array<string, float> */
    public function reliableDeviations(): array
    {
        $retenus = [];

        foreach ($this->rows as $ligne) {
            if ($ligne->deviation !== null) {
                $retenus[$ligne->key] = $ligne->deviation;
            }
        }

        return $retenus;
    }

    /** Y a-t-il de quoi reporter quoi que ce soit ? */
    public function hasAnythingToApply(): bool
    {
        return $this->reliableDeviations() !== [];
    }
}
