<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductNature;
use Merisu\Inventory\Domain\RoundingMode;
use PHPUnit\Framework\TestCase;

/**
 * La case « ce produit nécessite une recette ».
 *
 * Le drapeau lui-même est simple ; ce qui compte, c'est qu'il parte à FAUX par
 * défaut — une base déjà remplie ne doit pas voir tous ses produits réclamer
 * soudain une recette — et qu'il survive à une modification de fiche.
 */
final class ProductNeedsRecipeTest extends TestCase
{
    private static function produit(bool $needs = false): Product
    {
        return new Product(
            'p1', 'P1', ['fr' => 'Tiramisu'], 'pcs', true, 0.0, 1.0,
            RoundingMode::Ceil, null, 0,
            nature: ProductNature::Sale,
            needsRecipe: $needs,
        );
    }

    public function testFauxParDefaut(): void
    {
        // Le point qui compte : sur une base déjà en service, aucun produit ne
        // réclame de recette tant qu'on ne l'a pas coché.
        $p = new Product('p1', 'P1', ['fr' => 'x'], 'pcs', true, 0.0, 1.0, RoundingMode::Ceil, null, 0);

        self::assertFalse($p->needsRecipe);
    }

    public function testSeRetientQuandOnLeCoche(): void
    {
        self::assertTrue(self::produit(true)->needsRecipe);
    }

    public function testSurvitAUneModificationQuiNeLeTouchePas(): void
    {
        // Renommer une fiche ne doit pas décocher « nécessite une recette ».
        $p = self::produit(true)->with(name: ['fr' => 'Tiramisu Grand']);

        self::assertTrue($p->needsRecipe);
        self::assertSame('Tiramisu Grand', $p->name['fr']);
    }

    public function testSeDecocheQuandOnLeDemande(): void
    {
        $p = self::produit(true)->with(needsRecipe: false);

        self::assertFalse($p->needsRecipe);
    }
}
