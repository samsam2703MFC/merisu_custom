<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ProductNature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductNatureTest extends TestCase
{
    /** @return iterable<string, array{mixed, ProductNature}> */
    public static function valeursLues(): iterable
    {
        yield 'produit en vente' => ['SALE', ProductNature::Sale];
        yield 'recette' => ['RECIPE', ProductNature::Recipe];
        yield 'matière première' => ['RAW', ProductNature::Raw];
        yield 'emballage' => ['PACKAGING', ProductNature::Packaging];
        yield 'casse indifférente' => ['raw', ProductNature::Raw];
        yield 'casse mêlée' => ['Packaging', ProductNature::Packaging];
        // Ancien nom du produit en vente : les bases déjà en service le portent.
        yield 'ancien nom' => ['COMPOSED', ProductNature::Sale];
        yield 'ancien nom, autre casse' => ['composed', ProductNature::Sale];
    }

    #[DataProvider('valeursLues')]
    public function testUneValeurConnueEstLue(mixed $brut, ProductNature $attendu): void
    {
        self::assertSame($attendu, ProductNature::fromLoose($brut));
    }

    /** @return iterable<string, array{mixed}> */
    public static function valeursAberrantes(): iterable
    {
        yield 'vide' => [''];
        yield 'inconnue' => ['MATIERE'];
        yield 'nulle' => [null];
        yield 'tableau' => [['RAW']];
        yield 'objet' => [new \stdClass()];
    }

    /**
     * Une valeur qu'on ne sait pas lire vient d'une base ancienne, où la
     * colonne n'existait pas, ou d'une requête forgée. Replier sur une matière
     * l'aurait retirée du plan de production, replier sur une recette l'aurait
     * retirée de la vente : dans les deux cas, sur le seul motif d'une valeur
     * illisible.
     */
    #[DataProvider('valeursAberrantes')]
    public function testUneValeurAberranteResteUnProduitEnVente(mixed $brut): void
    {
        self::assertSame(ProductNature::Sale, ProductNature::fromLoose($brut));
    }

    /**
     * On ACHÈTE matières et emballages, on FABRIQUE recettes et produits en
     * vente. Toute la mécanique du module en découle : seuil posé à la main
     * d'un côté, minimum déduit et plan de production de l'autre.
     */
    public function testDeuxFamillesSelonQuOnAchetteOuQuOnFabrique(): void
    {
        foreach ([ProductNature::Sale, ProductNature::Recipe] as $fabriquee) {
            self::assertTrue($fabriquee->isProduced(), $fabriquee->value);
            self::assertFalse($fabriquee->isPurchased(), $fabriquee->value);
        }

        foreach ([ProductNature::Raw, ProductNature::Packaging] as $achetee) {
            self::assertTrue($achetee->isPurchased(), $achetee->value);
            self::assertFalse($achetee->isProduced(), $achetee->value);
        }
    }

    /** Décrire une barquette en termes de barquettes n'aurait aucun sens. */
    public function testSeulCeQuiSeFabriquePorteUneNomenclature(): void
    {
        self::assertTrue(ProductNature::Sale->canHaveRecipe());
        self::assertTrue(ProductNature::Recipe->canHaveRecipe());
        self::assertFalse(ProductNature::Raw->canHaveRecipe());
        self::assertFalse(ProductNature::Packaging->canHaveRecipe());
    }

    /**
     * Le produit en vente est le SOMMET de l'assemblage. L'admettre comme
     * composant ouvrirait la porte aux cycles — un tiramisu fait de tiramisu.
     */
    public function testLeProduitEnVenteNEntreDansLaCompositionDePersonne(): void
    {
        self::assertFalse(ProductNature::Sale->canBeComponent());
        self::assertTrue(ProductNature::Recipe->canBeComponent());
        self::assertTrue(ProductNature::Raw->canBeComponent());
        self::assertTrue(ProductNature::Packaging->canBeComponent());
    }

    /** Le produit en vente ouvre la liste : c'est le cas ordinaire. */
    public function testLesQuatreNaturesSontProposeesDansCetOrdre(): void
    {
        self::assertSame(
            [ProductNature::Sale, ProductNature::Recipe, ProductNature::Raw, ProductNature::Packaging],
            ProductNature::all(),
        );
    }

    public function testChaqueNatureAUneSilhouetteDistincte(): void
    {
        $icones = array_map(static fn (ProductNature $n): string => $n->icon(), ProductNature::all());

        self::assertSame($icones, array_unique($icones));
        self::assertSame('nature-sale', ProductNature::Sale->icon());
        self::assertSame('nature-recipe', ProductNature::Recipe->icon());
        self::assertSame('nature-raw', ProductNature::Raw->icon());
        self::assertSame('nature-packaging', ProductNature::Packaging->icon());
    }
}
