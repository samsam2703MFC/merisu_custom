<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\ProductCategory;
use Merisu\Inventory\Domain\ProductNature;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Catégories — la liste et l'ordre des catégories de production.
 *
 * Elles restent portées par le produit, sous forme de texte : cet écran ne
 * crée rien qui n'existe déjà sur une fiche. Ce qu'il apporte, c'est ce que le
 * texte libre ne pouvait pas donner —
 *
 * · RENOMMER en une fois. Corriger « Tiramisu » en « Tiramisù » demandait de
 *   rouvrir chaque fiche produit, et en oublier une créait une catégorie
 *   fantôme qui doublait le groupe à l'écran de comptage ;
 * · ORDONNER. Les groupes se présentaient dans l'ordre où les produits avaient
 *   été créés, ce qui n'a aucun rapport avec l'ordre dans lequel on parcourt
 *   une boutique ;
 * · FUSIONNER deux doublons, en renommant l'un vers l'autre.
 */
#[Route('/admin/categories')]
final class AdminCategoryController extends AbstractController
{
    public function __construct(
        private readonly Store $store,
        private readonly CurrentUser $currentUser,
    ) {
    }

    #[Route('', name: 'admin_categories', methods: ['GET'])]
    public function index(): Response
    {
        $this->currentUser->requireAdmin();

        $categories = $this->store->categories();
        $produits = $this->store->products(true);

        /*
          Produits dont la nature contredit celle de leur rayon.

          Ce n'est pas une faute : un produit fini rangé au frais parmi les
          matières est une situation réelle, et forcer l'un sur l'autre
          retirerait ou ajouterait des lignes au plan de production dans le dos
          de l'administrateur. On le SIGNALE donc, et lui tranche.
        */
        $natures = [];
        foreach ($categories as $categorie) {
            $natures[$categorie->name] = $categorie->nature;
        }

        $desaccords = [];
        foreach ($produits as $produit) {
            if (isset($natures[$produit->category]) && $natures[$produit->category] !== $produit->nature) {
                $desaccords[$produit->category] = ($desaccords[$produit->category] ?? 0) + 1;
            }
        }

        return $this->render('admin/categories.html.twig', [
            'categories' => $categories,
            'natures' => ProductNature::all(),
            'mismatches' => $desaccords,
            // Produits actifs sans catégorie : ils forment le fourre-tout de
            // l'écran de comptage, et il vaut mieux le savoir que le découvrir.
            'unclassified' => \count(array_filter(
                $produits,
                static fn ($p): bool => $p->category === '',
            )),
        ]);
    }

    /**
     * Enregistre l'ordre, et les renommages.
     *
     * Les deux dans la MÊME soumission : l'administrateur réordonne et corrige
     * un libellé d'un même geste, et deux boutons l'obligeraient à deviner
     * lequel valide quoi.
     */
    #[Route('', name: 'admin_categories_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        /** @var array<string,mixed> $noms */
        $noms = $request->request->all('name');
        /** @var array<string,mixed> $ordres */
        $ordres = $request->request->all('order');
        /** @var array<string,mixed> $natures */
        $natures = $request->request->all('nature');

        $renommees = 0;
        $reordonnees = 0;
        $bascules = 0;

        foreach ($this->store->categories() as $categorie) {
            $ancien = $categorie->name;

            // L'ordre et la nature d'abord : un renommage change la clé, et la
            // ligne visée ne serait plus la même ensuite.
            $ordre = (int) ($ordres[$ancien] ?? $categorie->sortOrder);
            if ($ordre !== $categorie->sortOrder) {
                $this->store->saveCategoryOrder($ancien, $ordre);
                ++$reordonnees;
            }

            $nature = ProductNature::fromLoose($natures[$ancien] ?? $categorie->nature->value);
            if ($nature !== $categorie->nature) {
                $this->store->saveCategoryNature($ancien, $nature);
                ++$bascules;
            }

            $nouveau = ProductCategory::clean((string) ($noms[$ancien] ?? $ancien));

            if ($nouveau === '' || $nouveau === $ancien) {
                continue;
            }

            $this->store->renameCategory($ancien, $nouveau);
            ++$renommees;
        }

        if ($renommees > 0 || $reordonnees > 0 || $bascules > 0) {
            $this->store->audit($admin->id, $admin->role->value, 'CATEGORIES_UPDATE', null, null, [
                'renamed' => $renommees,
                'reordered' => $reordonnees,
                'natureChanged' => $bascules,
            ]);
        }

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_categories');
    }

    /**
     * Crée une catégorie vide.
     *
     * Depuis que la fiche produit CHOISIT sa catégorie dans une liste, c'est ici
     * — et nulle part ailleurs — qu'on en ajoute une. Sans ce geste, la liste ne
     * pourrait plus s'enrichir que par accident, et la première boutique à
     * ouvrir un rayon nouveau se retrouverait bloquée.
     */
    #[Route('/ajouter', name: 'admin_categories_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $nom = ProductCategory::clean((string) $request->request->get('name', ''));

        if ($nom === '') {
            $this->addFlash('error', 'admin.categories.addEmpty');

            return $this->redirectToRoute('admin_categories');
        }

        $nature = ProductNature::fromLoose($request->request->get('nature'));

        if (!$this->store->addCategory($nom, $nature)) {
            $this->addFlash('error', 'admin.categories.addDuplicate');

            return $this->redirectToRoute('admin_categories');
        }

        $this->store->audit($admin->id, $admin->role->value, 'CATEGORY_CREATED', null, null, [
            'name' => $nom,
            'nature' => $nature->value,
        ]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_categories');
    }

    /**
     * Supprime une catégorie : ses produits deviennent « non classé ».
     *
     * Aucun produit n'est supprimé avec elle — une catégorie est une étiquette,
     * pas un contenant. L'écran le dit avant de demander confirmation.
     */
    #[Route('/{name}/supprimer', name: 'admin_categories_delete', methods: ['POST'])]
    public function delete(string $name): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $this->store->deleteCategory($name);
        $this->store->audit($admin->id, $admin->role->value, 'CATEGORY_DELETED', null, null, ['name' => $name]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_categories');
    }
}
