<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\SupplierSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupplierSourceTest extends TestCase
{
    /** @return iterable<string, array{mixed, SupplierSource}> */
    public static function valeursLues(): iterable
    {
        yield 'centrale' => ['CENTRAL', SupplierSource::Central];
        yield 'libre' => ['FREE', SupplierSource::Free];
        yield 'casse indifférente' => ['free', SupplierSource::Free];
        yield 'casse mêlée' => ['Central', SupplierSource::Central];
    }

    #[DataProvider('valeursLues')]
    public function testUneValeurConnueEstLue(mixed $brut, SupplierSource $attendu): void
    {
        self::assertSame($attendu, SupplierSource::fromLoose($brut));
    }

    /** @return iterable<string, array{mixed}> */
    public static function valeursAberrantes(): iterable
    {
        yield 'vide' => [''];
        yield 'inconnue' => ['GROSSISTE'];
        yield 'nulle' => [null];
        yield 'tableau' => [['FREE']];
        yield 'objet' => [new \stdClass()];
    }

    /**
     * Dans un réseau de franchise, l'achat centralisé est la règle et l'achat
     * libre l'exception qu'une boutique DÉCLARE. Replier sur « libre » aurait
     * laissé croire que chaque boutique se débrouille, alors que personne
     * n'a rien déclaré.
     */
    #[DataProvider('valeursAberrantes')]
    public function testUneValeurAberranteResteLaCentrale(mixed $brut): void
    {
        self::assertSame(SupplierSource::Central, SupplierSource::fromLoose($brut));
    }

    /**
     * Le nom du fournisseur n'apprend rien en centrale — c'est le réseau —
     * et devient la seule information utile en libre.
     */
    public function testLeNomDuFournisseurNeSertQuEnLibre(): void
    {
        self::assertFalse(SupplierSource::Central->needsSupplierName());
        self::assertTrue(SupplierSource::Free->needsSupplierName());
    }

    public function testLaCentraleOuvreLaListe(): void
    {
        self::assertSame([SupplierSource::Central, SupplierSource::Free], SupplierSource::all());
        self::assertTrue(SupplierSource::Central->isCentral());
        self::assertFalse(SupplierSource::Free->isCentral());
    }

    public function testChaqueOrigineAUneSilhouetteDistincte(): void
    {
        self::assertSame('supply-central', SupplierSource::Central->icon());
        self::assertSame('supply-free', SupplierSource::Free->icon());
    }
}
