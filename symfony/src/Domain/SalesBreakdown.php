<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Les ventes, regardées par jour, par jour de semaine, par semaine ou par mois.
 *
 * ── Une seule source, quatre vues
 *
 * Tout part du relevé produit × jour. La caisse saurait grouper elle-même,
 * mais quatre appels rendraient quatre vérités : faits à quatre instants sur
 * un jeu de commandes qui bouge, leurs totaux ne s'additionneraient plus.
 * Agrégé ici, le mois est par construction la somme de ses jours.
 *
 * ── Ce que l'agrégation ne fait PAS
 *
 * Elle n'invente aucune journée. Une clé n'existe que si une vente y est
 * tombée : un lundi férié ne devient pas une case à zéro, et la moyenne des
 * lundis n'est pas tirée vers le bas par un jour où la boutique était fermée.
 * L'écran montre les cases observées, pas un calendrier.
 */
final class SalesBreakdown
{
    /**
     * Range les ventes sous la période demandée.
     *
     * @param list<PosSale> $sales
     *
     * @return list<SalesBucket>
     */
    public static function of(array $sales, SalesPeriod $period): array
    {
        $quantites = [];
        $recettes = [];
        /** @var array<string, array<string, true>> $journees */
        $journees = [];

        foreach ($sales as $vente) {
            $cle = $period->keyFor($vente->date);

            $quantites[$cle] = ($quantites[$cle] ?? 0.0) + $vente->quantity;
            $recettes[$cle] = ($recettes[$cle] ?? 0.0) + $vente->revenue;
            // Les DATES distinctes, et non le nombre de lignes : quarante
            // produits vendus le même samedi font un samedi, pas quarante.
            $journees[$cle][$vente->date] = true;
        }

        $cases = [];
        foreach ($quantites as $cle => $quantite) {
            $cases[] = new SalesBucket(
                (string) $cle,
                Rounding::clean($quantite),
                Rounding::clean($recettes[$cle] ?? 0.0),
                count($journees[$cle] ?? []),
            );
        }

        usort($cases, static fn (SalesBucket $a, SalesBucket $b): int => $period->isChronological()
            // Le plus récent d'abord : on regarde ce qui vient de se passer.
            ? $b->key <=> $a->key
            // Lundi, mardi, mercredi — le seul ordre qu'on lise sans réfléchir.
            : $a->key <=> $b->key);

        return $cases;
    }

    /**
     * Le classement des produits sur l'intervalle.
     *
     * @param list<PosSale> $sales
     *
     * @return list<array{externalId: string, name: string, quantity: float, revenue: float, days: int}>
     */
    public static function byProduct(array $sales): array
    {
        $lignes = [];

        foreach ($sales as $vente) {
            $ligne = $lignes[$vente->externalId] ?? [
                'externalId' => $vente->externalId,
                'name' => $vente->name,
                'quantity' => 0.0,
                'revenue' => 0.0,
                'days' => [],
            ];

            $ligne['quantity'] += $vente->quantity;
            $ligne['revenue'] += $vente->revenue;
            $ligne['days'][$vente->date] = true;

            // Le libellé le plus récent l'emporte : un produit renommé dans la
            // caisse doit s'afficher sous son nom d'aujourd'hui.
            if ($vente->name !== '') {
                $ligne['name'] = $vente->name;
            }

            $lignes[$vente->externalId] = $ligne;
        }

        $sortie = [];
        foreach ($lignes as $ligne) {
            $ligne['quantity'] = Rounding::clean($ligne['quantity']);
            $ligne['revenue'] = Rounding::clean($ligne['revenue']);
            $ligne['days'] = count($ligne['days']);
            $sortie[] = $ligne;
        }

        // Du plus vendu au moins vendu : c'est l'ordre dans lequel on lit un
        // classement, et celui qui met sous les yeux ce qui compte.
        usort($sortie, static fn (array $a, array $b): int => [$b['quantity'], $a['name']] <=> [$a['quantity'], $b['name']]);

        return $sortie;
    }

    /**
     * Les ventes d'UN produit, sous la période demandée.
     *
     * @param list<PosSale> $sales
     *
     * @return list<SalesBucket>
     */
    public static function forProduct(array $sales, string $externalId, SalesPeriod $period): array
    {
        return self::of(
            array_values(array_filter($sales, static fn (PosSale $v): bool => $v->externalId === $externalId)),
            $period,
        );
    }
}
