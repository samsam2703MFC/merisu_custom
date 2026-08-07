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
        yield 'matière première' => ['RAW', ProductNature::Raw];
        yield 'composition' => ['COMPOSED', ProductNature::Composed];
        yield 'casse indifférente' => ['raw', ProductNature::Raw];
        yield 'casse mêlée' => ['Composed', ProductNature::Composed];
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
     * colonne n'existait pas, ou d'une requête forgée. Replier sur la MATIÈRE
     * PREMIÈRE retirerait le produit du plan de production sur ce seul motif,
     * et il manquerait en rayon le lendemain matin.
     */
    #[DataProvider('valeursAberrantes')]
    public function testUneValeurAberranteRestUneComposition(mixed $brut): void
    {
        self::assertSame(ProductNature::Composed, ProductNature::fromLoose($brut));
    }

    public function testSeuleLaCompositionSeFabrique(): void
    {
        self::assertTrue(ProductNature::Composed->isComposed());
        self::assertFalse(ProductNature::Composed->isRaw());
        self::assertTrue(ProductNature::Raw->isRaw());
        self::assertFalse(ProductNature::Raw->isComposed());
    }

    public function testLaBasculeFaitBienUnAllerRetour(): void
    {
        self::assertSame(ProductNature::Raw, ProductNature::Composed->toggled());
        self::assertSame(ProductNature::Composed, ProductNature::Raw->toggled());
        self::assertSame(ProductNature::Composed, ProductNature::Composed->toggled()->toggled());
    }

    /** La composition ouvre la liste : c'est elle que l'interrupteur propose d'abord. */
    public function testLesDeuxNaturesSontProposeesDansCetOrdre(): void
    {
        self::assertSame([ProductNature::Composed, ProductNature::Raw], ProductNature::all());
    }

    public function testChaqueNatureAUneSilhouetteDistincte(): void
    {
        self::assertSame('nature-composed', ProductNature::Composed->icon());
        self::assertSame('nature-raw', ProductNature::Raw->icon());
    }
}
