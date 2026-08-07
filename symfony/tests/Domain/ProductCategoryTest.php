<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductCategory;
use Merisu\Inventory\Domain\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductCategoryTest extends TestCase
{
    private static function produit(string $categorie): Product
    {
        return new Product(
            'p-' . $categorie,
            'CODE',
            ['fr' => 'X'],
            'pcs',
            true,
            0.0,
            1.0,
            RoundingMode::Ceil,
            null,
            1,
            category: $categorie,
        );
    }

    /** @param list<string> $categories */
    private static function groupes(array $categories): array
    {
        return array_map(
            static fn (string $c): array => ['category' => $c, 'products' => [self::produit($c)]],
            $categories,
        );
    }

    /** @param list<array{category: string, products: list<Product>}> $groupes */
    private static function noms(array $groupes): array
    {
        return array_map(static fn (array $g): string => $g['category'], $groupes);
    }

    // ── Nettoyage du nom ────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function nomsASalir(): iterable
    {
        yield 'espaces de bord' => ['  Tiramisu  ', 'Tiramisu'];
        yield 'espaces doublés' => ['Tiramisu   signature', 'Tiramisu signature'];
        yield 'tabulation' => ["Tiramisu\tsignature", 'Tiramisu signature'];
        yield 'saut de ligne' => ["Tiramisu\nsignature", 'Tiramisu signature'];
        yield 'déjà propre' => ['Boissons', 'Boissons'];
        yield 'vide' => ['   ', ''];
    }

    /**
     * Sans ce nettoyage, « Tiramisu » et « Tiramisu␣ » cohabiteraient comme
     * deux catégories distinctes, indiscernables à l'œil dans la liste.
     */
    #[DataProvider('nomsASalir')]
    public function testLeNomEstNettoye(string $brut, string $attendu): void
    {
        self::assertSame($attendu, ProductCategory::clean($brut));
    }

    public function testLeNomEstBorneEnLongueur(): void
    {
        self::assertSame(64, mb_strlen(ProductCategory::clean(str_repeat('é', 200))));
    }

    // ── Ordre des groupes ───────────────────────────────────────────────────

    public function testLesGroupesSuiventLOrdreDeReference(): void
    {
        $groupes = self::groupes(['Boissons', 'Tiramisu', 'Verrines']);

        $tries = ProductCategory::sortGroups($groupes, ['Tiramisu', 'Verrines', 'Boissons']);

        self::assertSame(['Tiramisu', 'Verrines', 'Boissons'], self::noms($tries));
    }

    /**
     * Une catégorie fraîchement saisie sur une fiche doit apparaître SANS
     * attendre qu'on soit passé l'ordonner, sinon elle semblerait perdue.
     */
    public function testUneCategorieInconnueVientApresLesOrdonnees(): void
    {
        $groupes = self::groupes(['Nouveauté', 'Tiramisu', 'Boissons']);

        $tries = ProductCategory::sortGroups($groupes, ['Tiramisu', 'Boissons']);

        self::assertSame(['Tiramisu', 'Boissons', 'Nouveauté'], self::noms($tries));
    }

    /** Le fourre-tout ferme toujours la marche : ce n'est pas une catégorie. */
    public function testLeGroupeSansCategorieFermeLaMarche(): void
    {
        $groupes = self::groupes(['', 'Tiramisu', 'Boissons']);

        $tries = ProductCategory::sortGroups($groupes, ['Boissons', 'Tiramisu']);

        self::assertSame(['Boissons', 'Tiramisu', ''], self::noms($tries));
    }

    /** Même sans ordre du tout, rien ne doit disparaître. */
    public function testSansOrdreDeReferenceRienNeSePerd(): void
    {
        $groupes = self::groupes(['Tiramisu', '', 'Boissons']);

        $tries = ProductCategory::sortGroups($groupes, []);

        self::assertCount(3, $tries);
        self::assertSame(['Tiramisu', 'Boissons', ''], self::noms($tries));
    }

    /**
     * Une référence citant des catégories absentes des groupes ne doit pas
     * inventer de groupes vides — l'écran afficherait des sections sans
     * produit.
     */
    public function testUnOrdreCitantDesAbsentesNInventeRien(): void
    {
        $groupes = self::groupes(['Tiramisu']);

        $tries = ProductCategory::sortGroups($groupes, ['Boissons', 'Tiramisu', 'Verrines']);

        self::assertSame(['Tiramisu'], self::noms($tries));
    }

    public function testAucunGroupeDonneAucunGroupe(): void
    {
        self::assertSame([], ProductCategory::sortGroups([], ['Tiramisu']));
    }

    /** Les produits de chaque groupe suivent leur groupe, intacts. */
    public function testLesProduitsRestentDansLeurGroupe(): void
    {
        $groupes = [
            ['category' => 'Boissons', 'products' => [self::produit('Boissons')]],
            ['category' => 'Tiramisu', 'products' => [self::produit('Tiramisu'), self::produit('Tiramisu')]],
        ];

        $tries = ProductCategory::sortGroups($groupes, ['Tiramisu', 'Boissons']);

        self::assertSame('Tiramisu', $tries[0]['category']);
        self::assertCount(2, $tries[0]['products']);
        self::assertCount(1, $tries[1]['products']);
    }
}
