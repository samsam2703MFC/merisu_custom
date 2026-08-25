<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Combien produire, jour par jour, et de quoi.
 *
 * Trois facteurs, dans cet ordre :
 *
 *   base × (1 + météo) × (1 + objectif) = pièces
 *
 * ── La base est un JOUR DE SEMAINE, pas une moyenne générale
 *
 * Un samedi vend deux fois et demie ce que vend un lundi — mesuré, pas
 * supposé. Une moyenne tous jours confondus aurait fait produire trop le
 * lundi et pas assez le samedi, chaque semaine, indéfiniment. On moyenne donc
 * les lundis entre eux.
 *
 * ── Six semaines, et ce qui manque se dit
 *
 * Assez pour lisser une semaine creuse, assez peu pour suivre une saison. Mais
 * une boutique ouverte depuis quinze jours n'a que deux samedis : la moyenne
 * est alors un souvenir, pas une prévision. On la rend quand même — il faut
 * bien produire — en disant sur combien d'observations elle repose.
 *
 * ── Ce que cette classe ne fait PAS
 *
 * Elle ne retranche pas le stock restant. Le plan dit ce qu'il faut AVOIR, le
 * comptage du soir dira ce qu'il reste, et la différence est un geste
 * d'atelier, pas un calcul : c'est le bon de production qui porte la ligne à
 * déduire. Retrancher ici un stock qui n'est pas encore compté aurait produit
 * un nombre faux tous les matins avant huit heures.
 */
final readonly class ProductionForecast
{
    /**
     * Occurrences minimales d'un jour de semaine pour que la base tienne.
     *
     * Quatre : en dessous, une seule journée hors norme — une fête, une
     * fermeture — déplace la moyenne de plus que l'objectif qu'on lui applique.
     */
    public const MIN_OBSERVATIONS = 4;

    /** Semaines regardées en arrière. */
    public const WEEKS = 6;

    /**
     * @param list<ProductionDay>   $days
     * @param array<string, float>  $mix      référence produit => part (0..1)
     * @param array<string, string> $names    référence produit => nom lisible
     */
    private function __construct(
        public array $days,
        public array $mix,
        public array $names,
        public int $totalPieces,
    ) {
    }

    /**
     * @param array<string, array<string, float>> $salesByDate date => (réf produit => quantité)
     * @param array<string, ForecastDay>          $weather     date => temps de ce jour
     * @param array<string, float>                $bandRatios  tranche => %
     * @param array<string, float>                $kindRatios  ciel => %
     * @param list<string>                        $dates       les jours à planifier, dans l'ordre
     * @param array<string, string>                $names       référence produit => nom lisible
     */
    public static function build(
        array $salesByDate,
        array $weather,
        array $bandRatios,
        array $kindRatios,
        array $dates,
        float $targetPercent,
        array $names = [],
    ): self {
        // ── La base, jour de semaine par jour de semaine ────────────────────
        $parJour = [];

        foreach ($salesByDate as $date => $lignes) {
            $jour = self::weekdayOf($date);

            if ($jour === null) {
                continue;
            }

            $parJour[$jour->value][] = array_sum($lignes);
        }

        // ── Le partage entre tailles, sur toute la période observée ─────────
        $parProduit = [];
        $totalVendu = 0.0;

        foreach ($salesByDate as $lignes) {
            foreach ($lignes as $ref => $qte) {
                $parProduit[(string) $ref] = ($parProduit[(string) $ref] ?? 0.0) + $qte;
                $totalVendu += $qte;
            }
        }

        $mix = [];
        if ($totalVendu > 0.0) {
            foreach ($parProduit as $ref => $qte) {
                $mix[$ref] = $qte / $totalVendu;
            }
            arsort($mix);
        }

        // ── Chaque journée demandée ─────────────────────────────────────────
        $jours = [];
        $total = 0;

        foreach ($dates as $date) {
            $jour = self::weekdayOf($date);

            if ($jour === null) {
                continue;
            }

            $observations = $parJour[$jour->value] ?? [];
            $base = $observations === [] ? 0.0 : array_sum($observations) / \count($observations);

            $temps = $weather[$date] ?? null;
            $tranche = $temps === null ? null : TemperatureBand::of($temps->tempMax);

            // La tranche de température d'abord, le ciel ensuite : c'est elle
            // qui porte l'écart le plus net, et cumuler les deux corrections
            // compterait deux fois une même journée chaude et ensoleillée.
            $correction = null;
            if ($tranche !== null && isset($bandRatios[$tranche->value])) {
                $correction = $bandRatios[$tranche->value];
            } elseif ($temps !== null && isset($kindRatios[$temps->kind->value])) {
                $correction = $kindRatios[$temps->kind->value];
            }

            $pieces = (int) ceil(
                $base
                * (1.0 + ($correction ?? 0.0) / 100.0)
                * (1.0 + $targetPercent / 100.0),
            );

            $jours[] = new ProductionDay(
                $date,
                $jour,
                round($base, 1),
                \count($observations),
                $correction,
                $tranche,
                $temps?->kind,
                $targetPercent,
                max(0, $pieces),
            );

            $total += max(0, $pieces);
        }

        return new self($jours, $mix, $names, $total);
    }

    /**
     * Ce que chaque produit représente sur la période, en pièces à produire.
     *
     * Réparti au prorata du partage OBSERVÉ : deviner les proportions aurait
     * fait produire des Extra qu'aucune boutique ne vend.
     *
     * @return array<string, int> référence produit => pièces
     */
    public function piecesByProduct(int $pieces): array
    {
        $repartition = [];

        foreach ($this->mix as $ref => $part) {
            $repartition[$ref] = (int) round($pieces * $part);
        }

        return $repartition;
    }

    /**
     * Les matières à sortir, pour un nombre de pièces donné.
     *
     * @param array<string, array<string, float>> $recipes   id produit => (id matière => par unité)
     * @param array<string, string>               $refToId   référence caisse => id produit
     *
     * @return array<string, float> id matière => quantité
     */
    public function materials(int $pieces, array $recipes, array $refToId): array
    {
        $besoin = [];

        foreach ($this->piecesByProduct($pieces) as $ref => $quantite) {
            $productId = $refToId[$ref] ?? null;

            // Un produit vendu mais sans composition ne consomme RIEN de
            // connu : l'ignorer vaut mieux que de lui prêter la recette du
            // voisin, ce qui gonflerait la commande sans que rien ne le dise.
            if ($productId === null || !isset($recipes[$productId])) {
                continue;
            }

            foreach ($recipes[$productId] as $materialId => $parUnite) {
                $besoin[(string) $materialId] = ($besoin[(string) $materialId] ?? 0.0) + $quantite * $parUnite;
            }
        }

        return $besoin;
    }

    /**
     * Les produits vendus mais dépourvus de composition — l'écran doit le dire.
     *
     * Sans cette liste, leurs pièces entreraient dans le total sans que leurs
     * matières entrent dans la commande : on produirait à partir de rien.
     *
     * @param array<string, array<string, float>> $recipes
     * @param array<string, string>               $refToId
     *
     * @return list<string>
     */
    public function productsWithoutRecipe(array $recipes, array $refToId): array
    {
        $orphelins = [];

        foreach (array_keys($this->mix) as $ref) {
            $productId = $refToId[$ref] ?? null;

            if ($productId === null || !isset($recipes[$productId])) {
                $orphelins[] = $this->names[$ref] ?? (string) $ref;
            }
        }

        return $orphelins;
    }

    private static function weekdayOf(string $date): ?DayOfWeek
    {
        $t = strtotime($date);

        return $t === false ? null : DayOfWeek::tryFrom(strtoupper(gmdate('D', $t)));
    }
}
