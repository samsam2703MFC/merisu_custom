<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Le coût d'une unité, résolu à travers les compositions.
 *
 * ── Il faut DESCENDRE, contrairement au reste du module
 *
 * Partout ailleurs, une recette est un stock compté : un tiramisu consomme
 * « 100 g de crème », et l'on s'arrête là. Pour le prix, non — la crème n'a
 * pas de tarif fournisseur, elle a un coût, et ce coût vient de ses propres
 * ingrédients. Il faut donc parcourir l'arbre jusqu'aux matières achetées.
 *
 * ── Et c'est là que les CYCLES cessent d'être théoriques
 *
 * Le module autorise deux recettes à se citer l'une l'autre : rien ne
 * récursive nulle part, et le pire qu'on risquait jusqu'ici était un chiffre
 * absurde. Ce calcul-ci, lui, boucle sans fin sur un tel montage — il ne
 * tomberait pas en erreur, il partirait pour toujours, et l'écran des recettes
 * resterait blanc sans que rien n'explique pourquoi.
 *
 * La descente porte donc la trace des fiches déjà traversées. Une fiche
 * rencontrée deux fois sur la même branche arrête la descente et se signale
 * comme irrésolvable — l'écran nomme le cycle plutôt que de se figer.
 *
 * ── Les pertes s'appliquent au COMPOSANT, pas au produit fini
 *
 * `wasteFactor` dit ce qu'on gâche de CET ingrédient-là : le mascarpone qui
 * reste au fond du pot, les biscuits cassés. C'est donc la quantité consommée
 * qui augmente, ingrédient par ingrédient, et non le coût final d'un bloc.
 * Appliquer une perte moyenne au total aurait fait disparaître l'écart entre
 * un ingrédient qu'on gâche et un qu'on ne gâche pas.
 */
final readonly class FoodCostCalculator
{
    /**
     * @param array<string, array<string, float>> $recipes  id produit => (id composant => quantité par unité)
     * @param array<string, Product>              $products id produit => fiche
     */
    public function __construct(
        private array $recipes,
        private array $products,
    ) {
    }

    /**
     * Le coût d'UNE unité du produit demandé.
     *
     * @param list<string> $chemin les fiches déjà traversées sur cette branche
     */
    public function costOf(string $productId, array $chemin = []): FoodCost
    {
        $produit = $this->products[$productId] ?? null;

        if ($produit === null) {
            return new FoodCost(0.0, 0.0, 0.0, false, [$productId]);
        }

        // Déjà rencontrée sur CETTE branche : on s'arrête là. Sans ce garde-
        // fou, deux recettes qui se citent l'une l'autre feraient tourner le
        // calcul indéfiniment — pas une erreur, un écran qui ne rend jamais.
        if (\in_array($productId, $chemin, true)) {
            return new FoodCost(0.0, 0.0, 0.0, false, [$produit->code . ' ↻']);
        }

        $lignes = $this->recipes[$productId] ?? [];

        /*
          Une fiche SANS composition tire son prix de son tarif d'achat.

          C'est le cas normal d'une matière et d'un emballage. C'en est un
          aussi, moins heureux, pour une recette dont la composition n'a pas
          été saisie : elle n'a alors ni tarif ni ingrédients, et son coût est
          déclaré incomplet plutôt que nul.
        */
        if ($lignes === []) {
            if ($produit->unitCost > 0.0) {
                return $produit->nature === ProductNature::Packaging
                    ? new FoodCost(0.0, $produit->unitCost, 0.0, true)
                    : new FoodCost($produit->unitCost, 0.0, 0.0, true);
            }

            return new FoodCost(0.0, 0.0, 0.0, false, [$produit->code]);
        }

        $cumul = FoodCost::empty();

        foreach ($lignes as $composantId => $quantite) {
            $composant = $this->products[(string) $composantId] ?? null;

            if ($composant === null) {
                $cumul = $cumul->plus(new FoodCost(0.0, 0.0, 0.0, false, [(string) $composantId]));

                continue;
            }

            $unitaire = $this->costOf((string) $composantId, [...$chemin, $productId]);

            // La perte porte sur CE composant : on en consomme davantage.
            $perte = max(0.0, $composant->wasteFactor);
            $consomme = $quantite * (1.0 + $perte);

            $ligne = $unitaire->times($consomme);

            // Ce que la perte a coûté, isolé pour être montré. Sans cela elle
            // se fondrait dans le total et personne ne chercherait à la
            // réduire — alors que c'est la seule part qu'un geste d'atelier
            // fait baisser sans rien renégocier.
            $surcout = $unitaire->times($quantite * $perte);

            $cumul = $cumul->plus(new FoodCost(
                $ligne->materials,
                $ligne->packaging,
                $ligne->waste + $surcout->materials + $surcout->packaging,
                $ligne->complete,
                $ligne->missing,
            ));
        }

        return new FoodCost(
            round($cumul->materials, 6),
            round($cumul->packaging, 6),
            round($cumul->waste, 6),
            $cumul->complete,
            $cumul->missing,
        );
    }

    /**
     * Le coût de chaque fiche qui porte une composition.
     *
     * @return array<string, FoodCost>
     */
    public function all(): array
    {
        $couts = [];

        foreach ($this->products as $id => $produit) {
            if ($produit->nature->canHaveRecipe()) {
                $couts[(string) $id] = $this->costOf((string) $id);
            }
        }

        return $couts;
    }
}
