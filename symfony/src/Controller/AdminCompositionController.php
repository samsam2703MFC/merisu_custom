<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\FoodCostCalculator;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductNature;
use Merisu\Inventory\Domain\RecipeLine;
use Merisu\Inventory\Domain\RecipeTemplate;
use Merisu\Inventory\Domain\RoundingMode;
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

        /*
          ── Deux vues, et les RECETTES par défaut

          L'écran s'appelle Recettes ; il listait pourtant les cinquante
          produits en vente à la suite, la plupart sans composition. On
          cherchait sa crème au milieu de quarante-neuf tiramisu.

          Les produits en vente ne DISPARAISSENT pas — leur composition doit
          bien se saisir quelque part, et le delta technique en dépend. Ils
          passent derrière un second onglet, dont le compteur dit combien ils
          sont : caché sans compteur, on croirait la fonction perdue.
        */
        $vue = $request->query->get('voir') === 'vente' ? 'vente' : 'recettes';

        /*
          Ce qui compte comme « recette » sur cet écran.

          Une nature RECIPE, bien sûr — mais aussi tout produit en vente qu'on
          a COCHÉ « nécessite une recette » dans Produits. C'est le sens de la
          case : le faire remonter ici, au lieu de le laisser parmi les
          quarante autres produits en vente où on ne le retrouvait pas.
        */
        $porteRecette = static fn (Product $p): bool => $p->nature === ProductNature::Recipe || $p->needsRecipe;

        $compte = ['recettes' => 0, 'vente' => 0];
        foreach ($assembles as $p) {
            $compte[$porteRecette($p) ? 'recettes' : 'vente']++;
        }

        $assembles = array_values(array_filter(
            $assembles,
            static fn (Product $p): bool => $vue === 'recettes' ? $porteRecette($p) : !$porteRecette($p),
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

        /*
          Le COÛT de chaque fiche.

          Calculé ici pour tout l'écran, en une fois : le faire fiche par fiche
          aurait relu la même nomenclature cinquante fois, et la descente à
          travers les recettes n'est pas gratuite.

          Toutes les fiches entrent dans le calculateur, pas seulement celles
          qu'on affiche : une crème peut être un ingrédient sans figurer dans
          la vue courante, et il faut bien la chiffrer pour chiffrer ce qui la
          contient.
        */
        $parId = [];
        foreach ($produits as $p) {
            $parId[$p->id] = $p;
        }

        $nomenclatures = [];
        foreach ($this->store->recipeLines() as $ligne) {
            $nomenclatures[$ligne->productId][$ligne->materialId] = $ligne->qtyPerUnit;
        }

        $calculateur = new FoodCostCalculator($nomenclatures, $parId);

        $couts = [];
        foreach ($assembles as $p) {
            $couts[$p->id] = $calculateur->costOf($p->id);
        }

        /*
          Le coût d'UNE unité de chaque composant.

          La question posée à l'écran — « combien coûte la recette ? » — ne se
          répond pas sans dire d'abord combien coûte CHAQUE ingrédient. Le même
          calculateur les chiffre : une matière achetée rend son tarif, une
          sous-recette descend jusqu'aux siennes. Sans cette colonne, le total
          tombait du ciel, et l'on ne savait pas lequel des ingrédients le
          faisait monter.
        */
        $coutsUnitaires = [];
        foreach ($composants as $m) {
            $coutsUnitaires[$m->id] = $calculateur->costOf($m->id);
        }

        $slot = $this->store->nextTemplateSlot();

        return $this->render('admin/compositions.html.twig', [
            'assembled' => $assembles,
            'costs' => $couts,
            'unitCosts' => $coutsUnitaires,
            'view' => $vue,
            'counts' => $compte,
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
     * Crée une composition — c'est-à-dire une RECETTE.
     *
     * Une composition et une recette sont la même chose vue de deux côtés : la
     * recette est la fiche, la composition est ce qu'elle contient. L'écran
     * n'en fabrique donc pas d'autre sorte. Un produit en VENTE, lui, se crée
     * dans Produits : il a un prix, un mode de comptage, une étiquette — tout
     * un attirail qui n'a rien à faire ici, et le dupliquer aurait fait deux
     * endroits où créer la même chose.
     *
     * La fiche naît VIDE et s'ouvre aussitôt : on vient de la nommer, l'étape
     * suivante est d'y mettre des lignes.
     */
    #[Route('/nouvelle', name: 'admin_composition_create', methods: ['POST'], priority: 10)]
    public function create(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $nom = trim((string) $request->request->get('name', ''));

        // Sans nom, la fiche n'est identifiable par personne : le code ne se
        // montre nulle part, et « PRODUIT_12 » dans une liste de recettes
        // n'aide en rien.
        if ($nom === '') {
            $this->addFlash('error', 'admin.compositions.createEmpty');

            return $this->redirectToRoute('admin_compositions');
        }

        $slot = $this->store->nextProductSlot();

        $recette = new Product(
            $slot['id'],
            $slot['code'],
            // Le même libellé dans les quatre langues : une recette est un nom
            // d'atelier, et laisser trois langues vides aurait affiché des
            // lignes blanches dans la composition d'un produit. Il se traduit
            // ensuite dans Produits, où l'on traduit déjà.
            array_fill_keys(array_map(
                static fn (Locale $l): string => $l->value,
                Locale::all(),
            ), mb_substr($nom, 0, 190)),
            mb_substr(trim((string) $request->request->get('unit', 'pcs')), 0, 16) ?: 'pcs',
            true,
            0.0,
            1.0,
            RoundingMode::Ceil,
            null,
            $slot['sortOrder'],
            nature: ProductNature::Recipe,
        );

        $this->store->saveProduct($recette);
        $this->store->audit($admin->id, $admin->role->value, 'RECIPE_CREATED', null, null, [
            'productId' => $slot['id'],
            'code' => $slot['code'],
        ]);

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_compositions', ['ouvrir' => $slot['id']]);
    }

    /**
     * Supprime une composition.
     *
     * Deux gestes portent le même mot, et la différence tient à ce qu'on a
     * sous les yeux.
     *
     * Sur une RECETTE, la fiche entière s'en va : elle n'existe que pour porter
     * une composition, et une recette sans lignes ne décrit rien.
     *
     * Sur un produit en VENTE, seules les LIGNES sont effacées. Le produit se
     * vend toujours ; il ne se fabrique simplement plus à partir de rien de
     * décrit. Le supprimer d'ici aurait retiré du catalogue un tiramisu parce
     * qu'on voulait refaire sa recette.
     *
     * Une recette EMPLOYÉE ailleurs n'est pas supprimée : la composition
     * parente y perdrait une ligne sans que rien ne l'annonce, et son delta
     * technique se mettrait à mentir. L'écran nomme alors qui l'emploie —
     * c'est la seule information qui permet d'agir.
     */
    #[Route('/{id}/supprimer', name: 'admin_composition_delete', methods: ['POST'])]
    public function delete(string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $produit = $this->store->product($id);
        if ($produit === null || !$produit->nature->canHaveRecipe()) {
            throw $this->createNotFoundException('PRODUCT_NOT_FOUND');
        }

        if ($produit->nature !== ProductNature::Recipe) {
            $this->store->replaceRecipe($id, []);
            $this->store->audit($admin->id, $admin->role->value, 'RECIPE_EMPTIED', null, null, ['productId' => $id]);
            $this->addFlash('success', 'admin.compositions.cleared');

            return $this->redirectToRoute('admin_compositions', ['ouvrir' => $id]);
        }

        if ($this->usedBy($id) !== []) {
            $this->addFlash('error', 'admin.compositions.deleteUsed');

            return $this->redirectToRoute('admin_compositions', ['ouvrir' => $id]);
        }

        // Une recette déjà comptée, ou déjà produite, a laissé des lignes que
        // sa fiche est seule à nommer. La retirer aurait laissé un historique
        // affichant des quantités sans produit.
        if ($this->store->productHasHistory($id)) {
            $this->addFlash('error', 'admin.compositions.deleteHistory');

            return $this->redirectToRoute('admin_compositions', ['ouvrir' => $id]);
        }

        // Les lignes d'abord : une fiche retirée en laissant sa nomenclature
        // aurait laissé en base des lignes rattachées à rien, que le prochain
        // produit à hériter de cet identifiant aurait ramassées.
        $this->store->replaceRecipe($id, []);
        $this->store->deleteProduct($id);

        $this->store->audit($admin->id, $admin->role->value, 'RECIPE_DELETED', null, null, [
            'productId' => $id,
            'code' => $produit->code,
        ]);

        $this->addFlash('success', 'admin.compositions.deleted');

        return $this->redirectToRoute('admin_compositions');
    }

    /**
     * Les compositions où ce produit ENTRE comme composant.
     *
     * @return list<Product>
     */
    private function usedBy(string $id): array
    {
        $employeurs = [];

        foreach ($this->store->recipeLines() as $ligne) {
            if ($ligne->materialId === $id) {
                $employeurs[$ligne->productId] = true;
            }
        }

        return array_values(array_filter(array_map(
            fn (string $productId): ?Product => $this->store->product($productId),
            array_keys($employeurs),
        )));
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
