<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductNature;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Store\ShopStore;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Le BOOK produit — une fiche par page, à imprimer et à relier.
 *
 * Ce que la boutique pose sur le comptoir : ce qu'on vend, ce qu'il y a
 * dedans, ce qui peut déclencher une allergie, combien de temps ça se garde.
 * Pas un écran d'administration de plus — une page de papier.
 *
 * ── Une page par produit, et pas une liste
 *
 * Une liste serrée tient sur trois feuilles et ne se consulte pas : au
 * comptoir, on cherche UN produit, sous les yeux d'un client qui attend. Une
 * page pleine par produit se feuillette, se plastifie, se pointe du doigt.
 * C'est plus de papier, et c'est le but.
 *
 * ── Ce qui est imprimé vient des FICHES, jamais d'ici
 *
 * Aucun texte de ce book n'est écrit dans le code. Les ingrédients, les
 * allergènes, la durée de vie se tiennent dans Produits, en quatre langues, et
 * se corrigent sans redéploiement. Un book qui porterait ses propres textes
 * aurait divergé de l'étiquette dès la première correction — et l'étiquette,
 * elle, engage la responsabilité de la boutique.
 *
 * ── Ce qui MANQUE est dit
 *
 * Un produit sans allergène renseigné n'imprime pas une ligne vide : il
 * imprime « à renseigner ». Une case vide se lit « aucun allergène », ce qui
 * est une affirmation — et une affirmation fausse, sur ce sujet, se paie.
 */
#[Route('/admin/produits')]
final class AdminProductBookExtra extends AbstractController
{
    public function __construct(
        private readonly Store $store,
        private readonly ShopStore $shops,
        private readonly CurrentUser $currentUser,
        private readonly LocaleAwareInterface $translator,
    ) {
    }

    #[Route('/book', name: 'admin_product_book', methods: ['GET'], priority: 10)]
    public function book(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $langue = Locale::tryFromLoose((string) $request->query->get('langue', ''))
            ?? Locale::tryFromLoose($request->getLocale())
            ?? Locale::Fr;

        /*
          La langue choisie vaut pour TOUTE la page, libellés compris.

          Sans cela, le book polonais de Wrocław sortait avec des noms de
          produits polonais sous des intertitres français — « Składniki »
          annoncé par « Ingrédients ». On imprime un objet destiné au
          comptoir : il n'a pas à porter la langue de l'administrateur qui
          l'édite.

          Posé sur la requête et non en session : changer la langue du book ne
          doit pas changer celle de l'administration qu'on retrouvera en
          revenant.
        */
        // Sur le TRADUCTEUR, pas sur la requête : celle-ci a déjà été lue au
        // démarrage du noyau, et la changer ici n'atteindrait plus `|trans`.
        $this->translator->setLocale($langue->value);

        $produits = $this->store->products(activeOnly: true);

        /*
          Ce qui SE VEND, et cela seul.

          Le book se pose au comptoir : une page « Crème mascarpone » n'y a pas
          sa place, personne n'en achète. Les recettes et les matières restent
          dans l'administration, où elles servent au calcul.
        */
        $vendus = array_values(array_filter(
            $produits,
            static fn (Product $p): bool => $p->nature === ProductNature::Sale,
        ));

        // Rangés par rayon puis par ordre d'affichage : le book suit la
        // vitrine, pas l'ordre d'insertion en base.
        usort(
            $vendus,
            static fn (Product $a, Product $b): int => [$a->category, $a->sortOrder, $a->code]
                <=> [$b->category, $b->sortOrder, $b->code],
        );

        // La composition de chaque page, pour dire de quoi c'est fait quand la
        // fiche le sait. Une seule lecture pour tout le book.
        $parProduit = [];
        foreach ($this->store->recipeLines() as $ligne) {
            $parProduit[$ligne->productId][$ligne->materialId] = $ligne->qtyPerUnit;
        }

        $nomsComposants = [];
        foreach ($produits as $p) {
            $nomsComposants[$p->id] = $p->label($langue);
        }

        return $this->render('admin/product_book.html.twig', [
            'products' => $vendus,
            'locale' => $langue,
            'locales' => Locale::all(),
            'lines' => $parProduit,
            'componentNames' => $nomsComposants,
            'shops' => $this->shops->all(activeOnly: true),
            'printedBy' => $admin->displayName(),
        ]);
    }
}
