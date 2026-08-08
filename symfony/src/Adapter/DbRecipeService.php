<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\RecipeLine;
use Merisu\Inventory\Store\Store;

/**
 * Nomenclatures tenues dans CE module, en attendant celles de TF Buddy.
 *
 * `LocalRecipeService` servait des recettes FICTIVES — quatre matières en dur,
 * six nomenclatures inventées — pour démontrer le calcul du delta technique.
 * Le delta tournait donc sur des chiffres qui ne décrivaient aucune boutique.
 *
 * Ici, tout vient de la base :
 *
 * · les MATIÈRES sont les produits de nature « matière première ». C'est la
 *   même liste que celle qu'on compte matin et soir, et c'est ce qui donne son
 *   sens au delta : comparer un théorique à un réel n'a d'intérêt que si les
 *   deux parlent de la même chose ;
 * · les NOMENCLATURES viennent d'Admin ▸ Recettes.
 *
 * ── Le jour où TF Buddy arrive
 *
 * Son `GET /api/v1/recipes/flatten` rend précisément la forme attendue ici —
 * `[produit][matière] => quantité par unité`. Écrire l'adaptateur reviendra
 * donc à appeler l'endpoint et à changer l'alias dans `services.yaml`. Restera
 * à faire le pont sur les identifiants de matière, `Product::supplierRef` étant
 * prévu pour cela.
 *
 * ⚠️ LECTURE SEULE, comme l'interface l'exige. La saisie passe par
 * `Store::replaceRecipe`, jamais par ici.
 */
final class DbRecipeService implements RecipeServiceInterface
{
    public function __construct(private readonly Store $store)
    {
    }

    public function recipes(array $productIds): array
    {
        return RecipeLine::flatten($this->store->recipeLines($productIds));
    }

    public function materials(): array
    {
        return array_map(self::toMaterial(...), $this->componentProducts());
    }

    public function material(string $id): ?Material
    {
        $product = $this->store->product($id);

        // Un produit EN VENTE n'est le composant de rien : le rendre ici
        // laisserait le delta technique comparer un tiramisu à lui-même.
        return $product === null || !$product->nature->canBeComponent()
            ? null
            : self::toMaterial($product);
    }

    /**
     * Tout ce qui peut ENTRER dans une nomenclature : matières, emballages et
     * préparations. Le produit en vente en est exclu — il est le sommet de
     * l'assemblage, et l'admettre ouvrirait la porte aux cycles.
     *
     * @return list<Product>
     */
    private function componentProducts(): array
    {
        return array_values(array_filter(
            $this->store->products(activeOnly: true),
            static fn (Product $p): bool => $p->nature->canBeComponent(),
        ));
    }

    /**
     * Une matière première VUE comme matière.
     *
     * Les libellés par langue passent tels quels : ce sont les mêmes données,
     * et les retraduire ici en aurait fait une deuxième source de vérité.
     */
    private static function toMaterial(Product $product): Material
    {
        $noms = [];
        foreach (Locale::all() as $locale) {
            $nom = trim($product->name[$locale->value] ?? '');
            if ($nom !== '') {
                $noms[$locale->value] = $nom;
            }
        }

        return new Material($product->id, $noms, $product->unit);
    }
}
