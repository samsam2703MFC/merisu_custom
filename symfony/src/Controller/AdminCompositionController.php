<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductNature;
use Merisu\Inventory\Domain\RecipeLine;
use Merisu\Inventory\Domain\RecipeTemplate;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\RecipeTemplateService;
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
 * ── Une fiche par chose FABRIQUÉE
 *
 * Un produit en vente s'assemble — un emballage, une ou plusieurs recettes,
 * parfois des matières directement. Une recette se fabrique à partir de
 * matières. Les deux portent donc une composition.
 *
 * Ce qui s'ACHÈTE n'en porte pas : une matière ou un emballage ne se fabrique
 * pas, ils ENTRENT dans la composition des autres. Ouvrir une fiche au
 * mascarpone inviterait à le décrire en termes de mascarpone.
 *
 * Et un produit en vente n'entre dans la composition de personne : il est le
 * sommet de l'assemblage, et l'admettre comme composant ouvrirait la porte aux
 * cycles — un tiramisu fait de tiramisu.
 *
 * ── Par unité, quitte à saisir par fournée
 *
 * L'atelier pense en fournées — « 4 l de lait pour 20 parts ». Le champ
 * « rendement » accepte cette forme et `RecipeLine::perUnit` la ramène à
 * l'unité avant enregistrement. Ce qui est stocké est toujours par unité,
 * faute de quoi une fournée passée de 20 à 24 parts fausserait tout
 * l'historique sans que rien ne le signale.
 */
#[Route('/admin/compositions')]
final class AdminCompositionController extends AbstractController
{
    public function __construct(
        private readonly Store $store,
        private readonly CurrentUser $currentUser,
        private readonly RecipeTemplateService $templates,
    ) {
    }

    #[Route('', name: 'admin_compositions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        $produits = $this->store->products(activeOnly: true);

        // Ce qui PORTE une composition : produits en vente et recettes.
        $assembles = array_values(array_filter(
            $produits,
            static fn (Product $p): bool => $p->nature->canHaveRecipe(),
        ));

        // Ce qui peut y ENTRER : emballages, recettes et matières — tout sauf
        // le produit en vente, sommet de l'assemblage.
        $composants = array_values(array_filter(
            $produits,
            static fn (Product $p): bool => $p->nature->canBeComponent(),
        ));

        // Rangés par nature : l'écran les regroupe sous des intertitres, et un
        // regroupement suppose que les semblables se suivent. L'ordre est celui
        // de l'enum, donc le même partout dans l'application.
        $rang = array_flip(array_map(
            static fn (ProductNature $n): string => $n->value,
            ProductNature::all(),
        ));
        usort(
            $composants,
            static fn (Product $a, Product $b): int => [$rang[$a->nature->value], $a->sortOrder]
                <=> [$rang[$b->nature->value], $b->sortOrder],
        );

        // Toutes les nomenclatures en une lecture : une par fiche en aurait
        // lancé autant que la boutique a de compositions.
        $parProduit = [];
        foreach ($this->store->recipeLines() as $ligne) {
            $parProduit[$ligne->productId][$ligne->materialId] = $ligne->qtyPerUnit;
        }

        $modeles = $this->store->recipeTemplates();
        $vises = [];
        foreach ($modeles as $modele) {
            $vises[$modele->id] = $this->templates->targets($modele);
        }

        $slot = $this->store->nextTemplateSlot();

        return $this->render('admin/compositions.html.twig', [
            'assembled' => $assembles,
            'components' => $composants,
            'natures' => ProductNature::all(),
            'lines' => $parProduit,
            // Les modèles, et ce que chacun VISERAIT. Montrer la liste avant
            // d'écrire est le contrepoids d'un rattachement par fragment de
            // nom : un modèle qui attrape un produit de trop se voit.
            'templates' => $modeles,
            'templateTargets' => $vises,
            'blankTemplate' => new RecipeTemplate($slot['id'], '', '', [], $slot['sortOrder']),
            // Fiche à déplier au chargement : celle qu'on vient d'enregistrer.
            'open' => (string) $request->query->get('ouvrir', ''),
            'openTemplate' => (string) $request->query->get('modele', ''),
        ]);
    }

    /**
     * Enregistre un modèle et ses lignes.
     *
     * Enregistrer n'APPLIQUE pas : ce sont deux gestes, et l'écran montre
     * entre les deux la liste des produits visés. Un modèle dont le fragment
     * est mal choisi doit pouvoir être corrigé sans avoir déjà écrit sur
     * quarante fiches.
     */
    #[Route('/modeles/{id}', name: 'admin_recipe_template_save', methods: ['POST'], priority: 10)]
    public function saveTemplate(Request $request, string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $nom = mb_substr(trim((string) $request->request->get('name', '')), 0, 190);
        $fragment = RecipeTemplate::cleanMatch((string) $request->request->get('match', ''));

        // Un fragment vide est contenu dans n'importe quel nom : le modèle se
        // serait posé sur le catalogue entier au premier clic.
        if ($fragment === '') {
            $this->addFlash('error', 'admin.compositions.templateNeedsMatch');

            return $this->redirectToRoute('admin_compositions');
        }

        $rendement = (float) str_replace(',', '.', (string) $request->request->get('yield', '1'));

        if ($rendement <= 0) {
            $this->addFlash('error', 'admin.compositions.badYield');

            return $this->redirectToRoute('admin_compositions');
        }

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

        $existant = $this->store->recipeTemplate($id);
        $ordre = $existant?->sortOrder ?? (int) $request->request->get('sortOrder', '0');

        $this->store->saveRecipeTemplate(
            new RecipeTemplate($id, $nom !== '' ? $nom : $fragment, $fragment, $lignes, $ordre),
            $lignes,
        );

        $this->store->audit($admin->id, $admin->role->value, 'RECIPE_TEMPLATE_SAVED', null, null, [
            'templateId' => $id,
            'match' => $fragment,
            'materials' => \count($lignes),
            'rejected' => $refusees,
        ]);

        $this->addFlash($refusees > 0 ? 'error' : 'success', $refusees > 0 ? 'admin.compositions.rejected' : 'common.saved');

        return $this->redirectToRoute('admin_compositions', ['modele' => $id]);
    }

    /** Pose le modèle sur tous les produits qu'il vise. */
    #[Route('/modeles/{id}/appliquer', name: 'admin_recipe_template_apply', methods: ['POST'], priority: 10)]
    public function applyTemplate(string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $modele = $this->store->recipeTemplate($id)
            ?? throw $this->createNotFoundException('TEMPLATE_NOT_FOUND');

        if (!$modele->isUsable()) {
            $this->addFlash('error', 'admin.compositions.templateEmpty');

            return $this->redirectToRoute('admin_compositions', ['modele' => $id]);
        }

        $bilan = $this->templates->apply($modele, $admin->id, $admin->role->value);

        $this->addFlash('success', [
            'key' => 'admin.compositions.templateApplied',
            'params' => ['%targets%' => $bilan['targets'], '%changed%' => $bilan['changed']],
        ]);

        return $this->redirectToRoute('admin_compositions', ['modele' => $id]);
    }

    #[Route('/modeles/{id}/supprimer', name: 'admin_recipe_template_delete', methods: ['POST'], priority: 10)]
    public function deleteTemplate(string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $this->store->deleteRecipeTemplate($id);
        $this->store->audit($admin->id, $admin->role->value, 'RECIPE_TEMPLATE_DELETED', null, null, ['templateId' => $id]);

        // Les compositions déjà posées RESTENT : le modèle est un outil de
        // saisie, pas un propriétaire. Les effacer aurait vidé quarante fiches
        // parce qu'on a rangé un raccourci.
        $this->addFlash('success', 'admin.compositions.templateDeleted');

        return $this->redirectToRoute('admin_compositions');
    }

    /**
     * Remplace la nomenclature d'un produit.
     *
     * Par remplacement complet plutôt que ligne à ligne : l'écran montre la
     * recette entière, et enregistrer doit donner exactement ce qu'on voit —
     * y compris les matières dont on vient de vider la quantité.
     */
    #[Route('/{id}', name: 'admin_composition_save', methods: ['POST'])]
    public function save(Request $request, string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $produit = $this->store->product($id);
        if ($produit === null || !$produit->nature->canHaveRecipe()) {
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
            $this->addFlash('error', 'admin.compositions.badYield');

            return $this->redirectToRoute('admin_compositions', ['ouvrir' => $id]);
        }

        $this->store->replaceRecipe($id, $lignes);
        $this->store->audit($admin->id, $admin->role->value, 'RECIPE_UPDATED', null, null, [
            'productId' => $id,
            'materials' => \count($lignes),
            'yield' => $rendement,
            'rejected' => $refusees,
        ]);

        $this->addFlash($refusees > 0 ? 'error' : 'success', $refusees > 0 ? 'admin.compositions.rejected' : 'common.saved');

        return $this->redirectToRoute('admin_compositions', ['ouvrir' => $id]);
    }
}
