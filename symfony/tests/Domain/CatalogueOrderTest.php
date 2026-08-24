<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\CatalogueOrder;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\RoundingMode;
use PHPUnit\Framework\TestCase;

final class CatalogueOrderTest extends TestCase
{
    private static function produit(
        string $id,
        string $nom,
        string $categorie = '',
        ?string $reference = null,
    ): Product {
        return new Product(
            $id,
            strtoupper($id),
            ['fr' => $nom],
            'pcs',
            true,
            0.0,
            1.0,
            RoundingMode::Ceil,
            $reference,
            0,
            category: $categorie,
        );
    }

    /**
     * @param list<Product> $produits
     * @param list<string>  $rayons
     *
     * @return list<string>
     */
    private static function noms(array $produits, array $rayons): array
    {
        $parId = [];
        foreach ($produits as $p) {
            $parId[$p->id] = $p->name['fr'];
        }

        return array_map(
            static fn (string $id): string => $parId[$id],
            CatalogueOrder::of($produits, $rayons),
        );
    }

    /**
     * Le désordre que cela répare.
     *
     * La caisse rend ses articles par ordre alphabétique : les rayons
     * s'entremêlent, et le vendeur qui compte le frigo saute d'une étagère à
     * l'autre à chaque ligne.
     */
    public function testLesProduitsSeRegroupentParRayon(): void
    {
        $produits = [
            self::produit('a', 'Berries Extra', 'Premium', '14'),
            self::produit('b', 'CaffeMisù', 'Coffee', '33'),
            self::produit('c', 'Berries Regular', 'Premium', '12'),
            self::produit('d', 'Espresso Moka', 'Coffee', '31'),
        ];

        self::assertSame(
            ['Espresso Moka', 'CaffeMisù', 'Berries Regular', 'Berries Extra'],
            self::noms($produits, ['Coffee', 'Premium']),
        );
    }

    /**
     * LE point : la référence de la caisse porte l'ordre des tailles.
     *
     * L'alphabet donne Extra, Grande, Regular — l'inverse de l'ordre où on les
     * range et où on les compte. La référence, elle, est l'ordre de création
     * dans la caisse : Regular (1), Grande (2), Extra (3).
     */
    public function testUneGammeRetrouveSonOrdreDeTaille(): void
    {
        $produits = [
            self::produit('a', 'Traditional Extra', 'Signature', '3'),
            self::produit('b', 'Traditional Regular', 'Signature', '1'),
            self::produit('c', 'Traditional Grande', 'Signature', '2'),
        ];

        self::assertSame(
            ['Traditional Regular', 'Traditional Grande', 'Traditional Extra'],
            self::noms($produits, ['Signature']),
        );
    }

    /** La référence se lit comme un NOMBRE : 9 vient avant 10, pas après. */
    public function testLaReferenceSeCompareEnNombreEtNonEnTexte(): void
    {
        $produits = [
            self::produit('a', 'Salted Caramel Extra', 'Premium', '11'),
            self::produit('b', 'Salted Caramel Regular', 'Premium', '9'),
            self::produit('c', 'Salted Caramel Grande', 'Premium', '10'),
        ];

        self::assertSame(
            ['Salted Caramel Regular', 'Salted Caramel Grande', 'Salted Caramel Extra'],
            self::noms($produits, ['Premium']),
        );
    }

    /** Les rayons suivent l'ordre du magasin, pas l'alphabet. */
    public function testLesRayonsSuiventLOrdreDAdminCategories(): void
    {
        $produits = [
            self::produit('a', 'Zèbre', 'Amaretto', '1'),
            self::produit('b', 'Abricot', 'Signature', '2'),
        ];

        self::assertSame(['Abricot', 'Zèbre'], self::noms($produits, ['Signature', 'Amaretto']));
        self::assertSame(['Zèbre', 'Abricot'], self::noms($produits, ['Amaretto', 'Signature']));
    }

    /**
     * Un rayon absent de la liste passe après ceux qui y sont, et les fiches
     * sans rayon ferment la marche.
     *
     * Les glisser au début aurait mis en tête les fiches dont personne n'a
     * encore décidé la place — les huit emplacements de démonstration, par
     * exemple, devant les quarante et un produits de la boutique.
     */
    public function testCeQuiNAPasDeRayonFermeLaMarche(): void
    {
        $produits = [
            self::produit('a', 'Cream 1'),
            self::produit('b', 'Inconnu', 'Rayon jamais déclaré', '5'),
            self::produit('c', 'Espresso', 'Coffee', '31'),
        ];

        self::assertSame(['Espresso', 'Inconnu', 'Cream 1'], self::noms($produits, ['Coffee']));
    }

    /**
     * Sans référence, on se range par le nom — mais DERRIÈRE ceux qui en ont
     * une. Mettre une fiche d'essai en tête du rayon n'aiderait personne.
     */
    public function testLesFichesSansReferencePassentApresLesAutres(): void
    {
        $produits = [
            self::produit('a', 'Aaa saisi à la main', 'Coffee'),
            self::produit('b', 'Zzz venu de la caisse', 'Coffee', '31'),
            self::produit('c', 'Bbb saisi à la main', 'Coffee'),
        ];

        self::assertSame(
            ['Zzz venu de la caisse', 'Aaa saisi à la main', 'Bbb saisi à la main'],
            self::noms($produits, ['Coffee']),
        );
    }

    /**
     * Une référence qui n'est pas un nombre ne sert pas de clé.
     *
     * D'autres caisses emploient des codes (« TIR-01 ») : les comparer comme
     * des nombres n'aurait aucun sens, et comme des textes remettrait
     * l'alphabet aux commandes.
     */
    public function testUneReferenceNonNumeriqueNeSertPasDeCle(): void
    {
        $produits = [
            self::produit('a', 'Bbb', 'Coffee', 'TIR-02'),
            self::produit('b', 'Aaa', 'Coffee', 'TIR-01'),
        ];

        self::assertSame(['Aaa', 'Bbb'], self::noms($produits, ['Coffee']));
    }

    public function testUnCatalogueVideNeRendRien(): void
    {
        self::assertSame([], CatalogueOrder::of([], ['Coffee']));
    }

    /** Le rangement est stable : le relancer ne bouge plus rien. */
    public function testLeRangementEstStable(): void
    {
        $produits = [
            self::produit('a', 'Berries Extra', 'Premium', '14'),
            self::produit('b', 'Espresso', 'Coffee', '31'),
            self::produit('c', 'Cream 1'),
        ];

        $premier = CatalogueOrder::of($produits, ['Coffee', 'Premium']);
        $second = CatalogueOrder::of($produits, ['Coffee', 'Premium']);

        self::assertSame($premier, $second);
        self::assertSame(['b', 'a', 'c'], $premier);
    }
}
