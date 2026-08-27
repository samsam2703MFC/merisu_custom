<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\FoodCost;
use Merisu\Inventory\Domain\FoodCostCalculator;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductNature;
use Merisu\Inventory\Domain\RoundingMode;
use PHPUnit\Framework\TestCase;

final class FoodCostCalculatorTest extends TestCase
{
    private static function produit(
        string $id,
        ProductNature $nature,
        float $prix = 0.0,
        float $perte = 0.0,
    ): Product {
        return new Product(
            $id,
            strtoupper($id),
            ['fr' => $id],
            'kg',
            true,
            $perte,
            1.0,
            RoundingMode::Ceil,
            null,
            0,
            nature: $nature,
            unitCost: $prix,
        );
    }

    /**
     * @param array<string, Product>              $produits
     * @param array<string, array<string, float>> $recettes
     */
    private static function calc(array $produits, array $recettes): FoodCostCalculator
    {
        return new FoodCostCalculator($recettes, $produits);
    }

    public function testUneMatiereCouteSonPrixDAchat(): void
    {
        $c = self::calc(['masc' => self::produit('masc', ProductNature::Raw, 12.0)], []);

        $cout = $c->costOf('masc');

        self::assertSame(12.0, $cout->materials);
        self::assertSame(0.0, $cout->packaging);
        self::assertTrue($cout->complete);
    }

    /** L'emballage compte à part : ce ne sont pas les mêmes leviers. */
    public function testLEmballageEstCompteSeparement(): void
    {
        $c = self::calc([
            'barq' => self::produit('barq', ProductNature::Packaging, 0.4),
            'masc' => self::produit('masc', ProductNature::Raw, 12.0),
            'tira' => self::produit('tira', ProductNature::Sale),
        ], ['tira' => ['masc' => 0.1, 'barq' => 1.0]]);

        $cout = $c->costOf('tira');

        self::assertSame(1.2, round($cout->materials, 4));
        self::assertSame(0.4, round($cout->packaging, 4));
        self::assertSame(1.6, $cout->total());
    }

    /**
     * Il faut DESCENDRE : la crème n'a pas de tarif fournisseur, elle a un
     * coût, et ce coût vient de ses propres ingrédients.
     */
    public function testLeCoutTraverseLesRecettes(): void
    {
        $c = self::calc([
            'masc' => self::produit('masc', ProductNature::Raw, 10.0),
            'sucre' => self::produit('sucre', ProductNature::Raw, 2.0),
            'creme' => self::produit('creme', ProductNature::Recipe),
            'tira' => self::produit('tira', ProductNature::Sale),
        ], [
            // 1 kg de crème = 0,8 kg de mascarpone + 0,2 kg de sucre = 8,4
            'creme' => ['masc' => 0.8, 'sucre' => 0.2],
            // 1 tiramisu = 0,1 kg de crème = 0,84
            'tira' => ['creme' => 0.1],
        ]);

        self::assertSame(8.4, round($c->costOf('creme')->materials, 4));
        self::assertSame(0.84, round($c->costOf('tira')->materials, 4));
        self::assertTrue($c->costOf('tira')->complete);
    }

    /**
     * LE garde-fou. Deux recettes qui se citent l'une l'autre feraient tourner
     * le calcul indéfiniment : pas une erreur, un écran qui ne rend jamais.
     */
    public function testUnCycleEntreRecettesNeBouclePas(): void
    {
        $c = self::calc([
            'a' => self::produit('a', ProductNature::Recipe),
            'b' => self::produit('b', ProductNature::Recipe),
        ], [
            'a' => ['b' => 1.0],
            'b' => ['a' => 1.0],
        ]);

        $cout = $c->costOf('a');

        self::assertFalse($cout->complete);
        self::assertContains('A ↻', $cout->missing);
    }

    /** Une fiche qui se cite elle-même s'arrête aussi, et se nomme. */
    public function testUneFicheQuiSeCiteElleMemeSArrete(): void
    {
        $c = self::calc(
            ['a' => self::produit('a', ProductNature::Recipe)],
            ['a' => ['a' => 1.0]],
        );

        $cout = $c->costOf('a');

        self::assertFalse($cout->complete);
        self::assertContains('A ↻', $cout->missing);
    }

    /**
     * Un seul composant sans prix rend le total FAUX, et faux vers le bas —
     * le pire sens, parce qu'il a l'air bon.
     */
    public function testUnIngredientSansPrixRendLeCoutIncomplet(): void
    {
        $c = self::calc([
            'masc' => self::produit('masc', ProductNature::Raw, 10.0),
            'cacao' => self::produit('cacao', ProductNature::Raw),   // sans prix
            'tira' => self::produit('tira', ProductNature::Sale),
        ], ['tira' => ['masc' => 0.1, 'cacao' => 0.003]]);

        $cout = $c->costOf('tira');

        self::assertFalse($cout->complete);
        self::assertContains('CACAO', $cout->missing);
        self::assertNull($cout->ratio(5.0));
    }

    /**
     * La perte porte sur CE composant : c'est la quantité consommée qui
     * augmente, ingrédient par ingrédient.
     */
    public function testLaPerteAugmenteLaQuantiteDeSonProreIngredient(): void
    {
        $c = self::calc([
            'masc' => self::produit('masc', ProductNature::Raw, 10.0, perte: 0.10),
            'sucre' => self::produit('sucre', ProductNature::Raw, 2.0),
            'tira' => self::produit('tira', ProductNature::Sale),
        ], ['tira' => ['masc' => 1.0, 'sucre' => 1.0]]);

        $cout = $c->costOf('tira');

        // 1 × 1,10 × 10 + 1 × 2 = 13
        self::assertSame(13.0, round($cout->materials, 4));
        // La perte a coûté 1 × 0,10 × 10 = 1
        self::assertSame(1.0, round($cout->waste, 4));
        self::assertSame(7.7, $cout->wasteShare());
    }

    public function testSansPerteRienNEstImpute(): void
    {
        $c = self::calc([
            'masc' => self::produit('masc', ProductNature::Raw, 10.0),
            'tira' => self::produit('tira', ProductNature::Sale),
        ], ['tira' => ['masc' => 1.0]]);

        self::assertSame(0.0, $c->costOf('tira')->waste);
        self::assertNull(FoodCost::empty()->wasteShare());
    }

    /** Une recette sans composition n'a ni tarif ni ingrédients : incomplète. */
    public function testUneRecetteSansCompositionEstIncomplete(): void
    {
        $c = self::calc(['creme' => self::produit('creme', ProductNature::Recipe)], []);

        $cout = $c->costOf('creme');

        self::assertFalse($cout->complete);
        self::assertSame(0.0, $cout->total());
    }

    public function testUnComposantInconnuEstNommeEtNonIgnore(): void
    {
        $c = self::calc(
            ['tira' => self::produit('tira', ProductNature::Sale)],
            ['tira' => ['fantome' => 1.0]],
        );

        self::assertFalse($c->costOf('tira')->complete);
        self::assertContains('fantome', $c->costOf('tira')->missing);
    }

    public function testLeRatioEstLeCoutSurLePrixDeVente(): void
    {
        $c = self::calc([
            'masc' => self::produit('masc', ProductNature::Raw, 10.0),
            'tira' => self::produit('tira', ProductNature::Sale),
        ], ['tira' => ['masc' => 0.1]]);

        // 1,00 de coût pour 4,00 de vente = 25 %
        self::assertSame(25.0, $c->costOf('tira')->ratio(4.0));
        // Sans prix de vente, pas de ratio inventé.
        self::assertNull($c->costOf('tira')->ratio(null));
        self::assertNull($c->costOf('tira')->ratio(0.0));
    }

    /** `all()` ne chiffre que ce qui PORTE une composition. */
    public function testSeulesLesFichesAssembleesSontChiffrees(): void
    {
        $c = self::calc([
            'masc' => self::produit('masc', ProductNature::Raw, 10.0),
            'barq' => self::produit('barq', ProductNature::Packaging, 0.4),
            'creme' => self::produit('creme', ProductNature::Recipe),
            'tira' => self::produit('tira', ProductNature::Sale),
        ], ['creme' => ['masc' => 1.0], 'tira' => ['creme' => 0.1, 'barq' => 1.0]]);

        $tous = $c->all();

        self::assertSame(['creme', 'tira'], array_keys($tous));
    }
}
