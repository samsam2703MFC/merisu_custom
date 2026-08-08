<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\RecipeLine;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Recettes — ce qu'une unité de produit consomme.
 *
 * C'est la donnée qui manquait au delta technique (§6). Il tournait jusqu'ici
 * sur `LocalRecipeService` : quatre matières en dur et six nomenclatures
 * inventées, qui ne décrivaient aucune boutique.
 *
 * ── Une fiche par COMPOSITION, et rien pour les matières
 *
 * Une matière première ne se fabrique pas : elle n'a pas de nomenclature, elle
 * EST une nomenclature pour les autres. Lui en ouvrir une inviterait à décrire
 * le mascarpone en termes de mascarpone.
 *
 * ── Par unité, quitte à saisir par fournée
 *
 * L'atelier pense en fournées — « 4 l de lait pour 20 parts ». Le champ
 * « rendement » accepte cette forme et `RecipeLine::perUnit` la ramène à
 * l'unité avant enregistrement. Ce qui est stocké est toujours par unité,
 * faute de quoi une fournée passée de 20 à 24 parts fausserait tout
 * l'historique sans que rien ne le signale.
 */
#[Route('/admin/recettes')]
final class AdminRecipeController extends AbstractController
{
    public function __construct(
        private readonly Store $store,
        private readonly CurrentUser $currentUser,
    ) {
    }

    #[Route('', name: 'admin_recipes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        $produits = $this->store->products(activeOnly: true);

        $compositions = array_values(array_filter($produits, static fn (Product $p): bool => $p->isProduced()));
        $matieres = array_values(array_filter($produits, static fn (Product $p): bool => !$p->isProduced()));

        // Toutes les nomenclatures en une lecture : une par fiche en aurait
        // lancé autant que la boutique a de compositions.
        $parProduit = [];
        foreach ($this->store->recipeLines() as $ligne) {
            $parProduit[$ligne->productId][$ligne->materialId] = $ligne->qtyPerUnit;
        }

        return $this->render('admin/recipes.html.twig', [
            'compositions' => $compositions,
            'materials' => $matieres,
            'lines' => $parProduit,
            // Fiche à déplier au chargement : celle qu'on vient d'enregistrer.
            'open' => (string) $request->query->get('ouvrir', ''),
        ]);
    }

    /**
     * Remplace la nomenclature d'un produit.
     *
     * Par remplacement complet plutôt que ligne à ligne : l'écran montre la
     * recette entière, et enregistrer doit donner exactement ce qu'on voit —
     * y compris les matières dont on vient de vider la quantité.
     */
    #[Route('/{id}', name: 'admin_recipe_save', methods: ['POST'])]
    public function save(Request $request, string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $produit = $this->store->product($id);
        if ($produit === null || !$produit->isProduced()) {
            throw $this->createNotFoundException('PRODUCT_NOT_FOUND');
        }

        // Rendement : « pour combien d'unités » les quantités saisies valent.
        // 1 par défaut — c'est-à-dire « les quantités sont déjà par unité ».
        $rendement = (float) str_replace(',', '.', (string) $request->request->get('yield', '1'));

        /** @var array<string,mixed> $saisies */
        $saisies = $request->request->all('qty');
        $lignes = [];
        $refusees = 0;

        foreach ($saisies as $materialId => $brut) {
            $valeur = trim((string) $brut);
            if ($valeur === '') {
                continue;
            }

            $parUnite = RecipeLine::perUnit((float) str_replace(',', '.', $valeur), $rendement);

            if ($parUnite === null) {
                ++$refusees;
                continue;
            }

            $lignes[(string) $materialId] = $parUnite;
        }

        // Un rendement nul ou négatif rend TOUTES les lignes indéchiffrables :
        // enregistrer une recette vide effacerait celle qui existait.
        if ($rendement <= 0) {
            $this->addFlash('error', 'admin.recipes.badYield');

            return $this->redirectToRoute('admin_recipes', ['ouvrir' => $id]);
        }

        $this->store->replaceRecipe($id, $lignes);
        $this->store->audit($admin->id, $admin->role->value, 'RECIPE_UPDATED', null, null, [
            'productId' => $id,
            'materials' => \count($lignes),
            'yield' => $rendement,
            'rejected' => $refusees,
        ]);

        $this->addFlash($refusees > 0 ? 'error' : 'success', $refusees > 0 ? 'admin.recipes.rejected' : 'common.saved');

        return $this->redirectToRoute('admin_recipes', ['ouvrir' => $id]);
    }
}
