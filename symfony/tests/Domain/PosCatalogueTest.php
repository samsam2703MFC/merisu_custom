<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\PosCategory;
use Merisu\Inventory\Domain\PosItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La lecture du catalogue GoPOS.
 *
 * Ce qui compte ici n'est pas ce qu'on garde, mais ce qu'on ÉCARTE : un
 * modificateur, un article supprimé, une ligne sans nom. Chacun d'eux, laissé
 * passer, se retrouvait le lendemain sur l'écran de comptage du vendeur.
 */
final class PosCatalogueTest extends TestCase
{
    // ── Articles ────────────────────────────────────────────────────────────

    public function testUnArticleOrdinaireEstRepris(): void
    {
        $article = PosItem::fromHost([
            'id' => 4218,
            'name' => '  Tiramisu Classico  ',
            'sku' => 'TIR-01',
            'category_id' => 12,
            'category' => ['id' => 12, 'name' => 'Tiramisu'],
            'status' => 'ENABLED',
            'type' => 'PRODUCT',
        ]);

        self::assertNotNull($article);
        self::assertSame('4218', $article->externalId);
        self::assertSame('Tiramisu Classico', $article->name);
        self::assertSame('TIR-01', $article->sku);
        self::assertSame('12', $article->categoryId);
        self::assertSame('Tiramisu', $article->categoryName);
        self::assertTrue($article->enabled);
    }

    /**
     * Un modificateur (« supplément cacao ») n'est pas une ligne qu'on compte
     * au frigo. Le laisser entrer aurait rempli l'inventaire de lignes sans
     * stock, que le vendeur aurait dû compter chaque matin.
     */
    #[DataProvider('articlesEcartes')]
    public function testCeQuiNAPasSaPlaceEstEcarte(array $ligne): void
    {
        self::assertNull(PosItem::fromHost($ligne));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function articlesEcartes(): iterable
    {
        yield 'modificateur' => [['id' => 1, 'name' => 'Supplément cacao', 'type' => 'MODIFIER']];
        yield 'formule' => [['id' => 1, 'name' => 'Menu midi', 'type' => 'PACKAGE']];
        yield 'supprimé en caisse' => [['id' => 1, 'name' => 'Ancien produit', 'type' => 'PRODUCT', 'status' => 'DELETED']];
        yield 'sans nom' => [['id' => 1, 'name' => '   ', 'type' => 'PRODUCT']];
        yield 'sans identifiant' => [['name' => 'Tiramisu', 'type' => 'PRODUCT']];
        yield 'identifiant vide' => [['id' => '', 'name' => 'Tiramisu', 'type' => 'PRODUCT']];
    }

    /** Désactivé n'est pas supprimé : la fiche entre, mais inactive. */
    public function testUnArticleDesactiveEntreMaisInactif(): void
    {
        $article = PosItem::fromHost(['id' => 7, 'name' => 'Saisonnier', 'type' => 'PRODUCT', 'status' => 'DISABLED']);

        self::assertNotNull($article);
        self::assertFalse($article->enabled);
    }

    /** Faute de `sku`, c'est `reference_id` qui sert — mais jamais une chaîne vide. */
    public function testLaReferenceSeReplieSurReferenceId(): void
    {
        $avecRef = PosItem::fromHost(['id' => 7, 'name' => 'X', 'type' => 'PRODUCT', 'sku' => '', 'reference_id' => 'REF-9']);
        $sansRien = PosItem::fromHost(['id' => 7, 'name' => 'X', 'type' => 'PRODUCT']);

        self::assertSame('REF-9', $avecRef?->sku);
        self::assertNull($sansRien?->sku);
    }

    /** Un type absent vaut PRODUCT : la caisse ne le renvoie pas toujours. */
    public function testUnTypeAbsentVautProduit(): void
    {
        self::assertNotNull(PosItem::fromHost(['id' => 7, 'name' => 'Tiramisu']));
    }

    // ── Catégories ──────────────────────────────────────────────────────────

    public function testUneCategorieOrdinaireEstReprise(): void
    {
        $categorie = PosCategory::fromHost(['id' => 12, 'name' => 'Tiramisu', 'status' => 'ENABLED']);

        self::assertNotNull($categorie);
        self::assertSame('12', $categorie->externalId);
        self::assertSame('Tiramisu', $categorie->name);
        self::assertTrue($categorie->enabled);
    }

    /**
     * Une catégorie supprimée chez la caisse ne doit pas revenir dans la liste
     * sous prétexte qu'on la recopie.
     */
    public function testUneCategorieSupprimeeNeRevientPas(): void
    {
        self::assertNull(PosCategory::fromHost(['id' => 12, 'name' => 'Ancienne', 'status' => 'DELETED']));
    }

    public function testUneCategorieSansNomEstEcartee(): void
    {
        self::assertNull(PosCategory::fromHost(['id' => 12, 'name' => '']));
        self::assertNull(PosCategory::fromHost(['name' => 'Tiramisu']));
    }
}
