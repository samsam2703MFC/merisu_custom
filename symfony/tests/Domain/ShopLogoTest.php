<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\Shop;
use PHPUnit\Framework\TestCase;

/** Le logo d'une boutique : personnalisé, défaut de ville, ou initiale. */
final class ShopLogoTest extends TestCase
{
    private static function shop(string $name, string $logoPath = ''): Shop
    {
        return new Shop('id', 'CODE', $name, logoPath: $logoPath);
    }

    public function testUneVilleConnueRecoitSonLogoLivre(): void
    {
        self::assertSame('assets/shops/wroclaw.webp', self::shop('Wrocław Rynek')->iconPath());
        self::assertSame('assets/shops/krakow.webp', self::shop('Kraków Floriańska')->iconPath());
        self::assertSame('assets/shops/poznan.webp', self::shop('Poznań Półwiejska')->iconPath());
        self::assertTrue(self::shop('Wrocław Rynek')->hasLogo());
    }

    public function testLAccentEtLaCasseNEmpechentPasLaReconnaissance(): void
    {
        // Le ł, le ó, la majuscule : rien de tout cela ne doit rendre le logo muet.
        self::assertSame('assets/shops/wroclaw.webp', self::shop('WROCLAW centrum')->iconPath());
        self::assertSame('assets/shops/krakow.webp', self::shop('krakow')->iconPath());
    }

    public function testUneImageChargeeALaMainGagneToujours(): void
    {
        $shop = self::shop('Wrocław Rynek', 'uploads/abc.png');

        self::assertSame('uploads/abc.png', $shop->iconPath());
        self::assertTrue($shop->hasCustomLogo());
    }

    public function testUneVilleInconnueRetombeSurLInitiale(): void
    {
        $shop = self::shop('Warszawa Nowy Świat');

        self::assertSame('', $shop->iconPath());
        self::assertFalse($shop->hasLogo());
        self::assertFalse($shop->hasCustomLogo());
        self::assertSame('W', $shop->initial());
    }

    public function testLeDefautDeVilleNEstPasUneImagePersonnalisee(): void
    {
        // On ne « retire » pas un défaut de ville : il n'a pas été chargé à la main.
        $shop = self::shop('Kraków Floriańska');

        self::assertTrue($shop->hasLogo());
        self::assertFalse($shop->hasCustomLogo());
    }
}
