<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une ligne de nomenclature : ce qu'UNE unité d'un produit consomme.
 *
 * « Un tiramisu classique consomme 0,2 l de lait. » C'est la brique du delta
 * technique (§6) : théorique = produit × quantité par unité, comparé au réel
 * relevé sur les matières.
 *
 * ── Par UNITÉ, et non par fournée
 *
 * Une recette d'atelier se pense en fournées — « 4 l de lait pour 20 parts ».
 * Stockée telle quelle, elle obligerait chaque calcul à connaître aussi le
 * rendement, et une fournée passée de 20 à 24 parts aurait faussé tout
 * l'historique sans que rien ne le signale. La saisie accepte les deux formes ;
 * ce qui est CONSERVÉ est toujours ramené à l'unité.
 *
 * ── Le vocabulaire est celui de l'hôte
 *
 * TF Buddy expose `recipes/flatten`, qui rend exactement
 * `[produit][matière] => quantité par unité`. C'est la forme que
 * `RecipeServiceInterface::recipes()` attend déjà, et celle qu'on stocke ici :
 * le jour où l'adaptateur arrive, il n'y a rien à convertir.
 *
 * Les SOUS-RECETTES de TF Buddy — une recette qui en appelle une autre —
 * n'existent pas ici : la nomenclature est plate, une composition citant
 * directement ses matières. C'est `flatten` qui fera le pont, puisque c'est
 * précisément son rôle.
 */
final readonly class RecipeLine
{
    public function __construct(
        public string $productId,
        public string $materialId,
        /** Quantité de matière pour UNE unité de produit, dans l'unité de la matière. */
        public float $qtyPerUnit,
    ) {
    }

    /**
     * Ramène une saisie « pour N unités » à la quantité pour une seule.
     *
     * Renvoie null quand la saisie ne veut rien dire — quantité négative,
     * rendement nul ou négatif. Null plutôt que zéro : une ligne à zéro dirait
     * « ce produit ne consomme pas de lait », ce qui est une affirmation, quand
     * une saisie fautive n'affirme rien.
     */
    public static function perUnit(float $quantity, float $yield = 1.0): ?float
    {
        if ($quantity < 0 || $yield <= 0 || !is_finite($quantity) || !is_finite($yield)) {
            return null;
        }

        return Rounding::clean($quantity / $yield);
    }

    /**
     * Nomenclatures au format attendu par le delta technique.
     *
     * @param list<RecipeLine> $lines
     *
     * @return array<string, array<string, float>> [productId][materialId] => qtyPerUnit
     */
    public static function flatten(array $lines): array
    {
        $sortie = [];

        foreach ($lines as $ligne) {
            // Une matière citée deux fois pour le même produit s'ADDITIONNE :
            // « 0,1 l de lait dans la crème, 0,1 l dans le nappage » fait bien
            // 0,2 l. Écraser aurait perdu la moitié de la consommation.
            $sortie[$ligne->productId][$ligne->materialId] =
                ($sortie[$ligne->productId][$ligne->materialId] ?? 0.0) + $ligne->qtyPerUnit;
        }

        return $sortie;
    }
}
