<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\RecipeLine;
use Merisu\Inventory\Domain\RecipeTemplate;
use Merisu\Inventory\Store\Store;

/**
 * Poser un modèle de composition sur les produits qu'il vise.
 *
 * ── Poser, et non remplacer
 *
 * Le modèle écrit SES matières et laisse les autres. Un modèle « taille »
 * pose la crème, les biscuits, le café et le cacao ; si le pistache portait
 * en plus sa pâte de pistache, elle reste. Sans cette règle, automatiser les
 * quantités aurait effacé tout ce qui fait la différence entre deux parfums,
 * et l'on aurait ressaisi à la main ce qu'on venait de gagner.
 *
 * ── Deux fois vaut une fois
 *
 * Appliquer le même modèle deux fois de suite donne le même résultat
 * qu'une : les lignes remplacent, elles ne s'ajoutent pas. Un clic de trop ne
 * doit pas doubler les quantités de tout le catalogue en silence.
 */
final readonly class RecipeTemplateService
{
    public function __construct(private Store $store)
    {
    }

    /**
     * Les produits qu'un modèle viserait — à MONTRER avant d'écrire.
     *
     * Le rattachement se fait sur un fragment de nom : c'est grossier, et
     * c'est pourquoi l'écran affiche toujours la liste. Un modèle qui attrape
     * un produit de trop se voit ; une règle savante mais invisible aurait
     * écrit en silence.
     *
     * @return list<Product>
     */
    public function targets(RecipeTemplate $template): array
    {
        return $template->targets($this->store->products(activeOnly: true));
    }

    /**
     * Pose le modèle sur tous les produits visés.
     *
     * @return array{targets: int, changed: int}
     */
    public function apply(
        RecipeTemplate $template,
        ?string $actorId = null,
        ?string $actorRole = null,
    ): array {
        $vises = $this->targets($template);

        if ($vises === [] || $template->lines === []) {
            return ['targets' => count($vises), 'changed' => 0];
        }

        // Toutes les nomenclatures en une lecture : une par produit en aurait
        // lancé autant que le modèle vise de fiches.
        $existantes = [];
        foreach ($this->store->recipeLines(array_map(static fn (Product $p): string => $p->id, $vises)) as $ligne) {
            $existantes[$ligne->productId][$ligne->materialId] = $ligne->qtyPerUnit;
        }

        $changes = 0;

        foreach ($vises as $produit) {
            $avant = $existantes[$produit->id] ?? [];
            $apres = $template->applyTo($avant);

            // Seules les fiches qui bougent sont réécrites : sans cela, un
            // modèle appliqué deux fois aurait signalé vingt-sept changements
            // alors que rien n'a changé.
            if (self::identiques($avant, $apres)) {
                continue;
            }

            $this->store->replaceRecipe($produit->id, $apres);
            ++$changes;
        }

        if ($changes > 0 && $actorId !== null && $actorRole !== null) {
            $this->store->audit($actorId, $actorRole, 'RECIPE_TEMPLATE_APPLIED', null, null, [
                'templateId' => $template->id,
                'match' => RecipeTemplate::cleanMatch($template->match),
                'targets' => count($vises),
                'changed' => $changes,
            ]);
        }

        return ['targets' => count($vises), 'changed' => $changes];
    }

    /**
     * Deux compositions disent-elles la même chose ?
     *
     * Les quantités passent par `Rounding::clean` avant comparaison : deux
     * chemins de calcul différents peuvent donner 0,1 et 0,10000000000000001,
     * et réécrire quarante fiches pour cet écart-là n'apprendrait rien à
     * personne.
     *
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    private static function identiques(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $materialId => $quantite) {
            if (!\array_key_exists($materialId, $b)) {
                return false;
            }

            if (RecipeLine::perUnit($quantite) !== RecipeLine::perUnit($b[$materialId])) {
                return false;
            }
        }

        return true;
    }
}
