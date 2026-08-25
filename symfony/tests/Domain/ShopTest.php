<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\Shop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShopTest extends TestCase
{
    private static function boutique(float $lat = 51.1097, float $lon = 17.0325): Shop
    {
        return new Shop('shop-1', 'BOUTIQUE_1', 'Wrocław Rynek', 'Rynek 12', '50-106', 'Wrocław', $lat, $lon);
    }

    // ── Le code ─────────────────────────────────────────────────────────────

    /**
     * Le code voyage dans des URL, des noms de fichiers et des charges utiles
     * envoyées à l'hôte. Un espace ou un accent s'y encode différemment selon
     * le chemin emprunté, et deux encodages du même code auraient fait deux
     * boutiques.
     */
    #[DataProvider('codes')]
    public function testLeCodeEstRameneACeQuiVoyageSansSeDeformer(string $brut, string $attendu): void
    {
        self::assertSame($attendu, Shop::cleanCode($brut));
    }

    /** @return iterable<string, array{string, string}> */
    public static function codes(): iterable
    {
        yield 'déjà propre' => ['BOUTIQUE_1', 'BOUTIQUE_1'];
        yield 'minuscules' => ['boutique_1', 'BOUTIQUE_1'];
        yield 'espaces' => ['Wroclaw Rynek', 'WROCLAW_RYNEK'];
        yield 'accents et ponctuation' => ['Kraków — Floriańska', 'KRAK_W_FLORIA_SKA'];
        yield 'tirets bas en trop aux bords' => ['__RYNEK__', 'RYNEK'];
        yield 'vide' => ['   ', ''];
    }

    public function testLeCodeEstBorne(): void
    {
        self::assertSame(Shop::CODE_MAX, mb_strlen(Shop::cleanCode(str_repeat('A', Shop::CODE_MAX + 20))));
    }

    // ── Les coordonnées ─────────────────────────────────────────────────────

    /**
     * Le point (0, 0) est REFUSÉ.
     *
     * Il tombe dans le golfe de Guinée, et c'est exactement ce que rend un
     * formulaire dont les deux champs sont restés vides. Une boutique sans
     * coordonnées est une boutique sans météo — pas une boutique au large de
     * l'Afrique.
     */
    #[DataProvider('points')]
    public function testCeQuiFaitDesCoordonneesUtilisables(float $lat, float $lon, bool $attendu): void
    {
        self::assertSame($attendu, self::boutique($lat, $lon)->hasCoordinates());
    }

    /** @return iterable<string, array{float, float, bool}> */
    public static function points(): iterable
    {
        yield 'Wrocław' => [51.1097, 17.0325, true];
        yield 'Varsovie' => [52.2337, 21.0189, true];
        yield 'deux champs vides' => [0.0, 0.0, false];
        yield 'latitude hors du globe' => [91.0, 17.0, false];
        yield 'longitude hors du globe' => [51.0, 181.0, false];
    }

    // ── L'adresse ───────────────────────────────────────────────────────────

    public function testLAdresseSeLitEnUneLigne(): void
    {
        self::assertSame('Rynek 12, 50-106 Wrocław', self::boutique()->addressLine());
    }

    /** Une adresse à moitié saisie ne laisse ni virgule ni espace orphelins. */
    public function testUneAdresseIncompleteNeLaissePasDePonctuationSeule(): void
    {
        self::assertSame('Rynek 12', (new Shop('s', 'S', 'X', 'Rynek 12'))->addressLine());
        self::assertSame('Wrocław', (new Shop('s', 'S', 'X', '', '', 'Wrocław'))->addressLine());
        self::assertSame('50-106', (new Shop('s', 'S', 'X', '', '50-106'))->addressLine());
        self::assertSame('', (new Shop('s', 'S', 'X'))->addressLine());
    }

    // ── La modification ─────────────────────────────────────────────────────

    /**
     * `with` ne touche QUE ce qu'on lui donne.
     *
     * Le code et l'identifiant n'en font jamais partie : ce sont eux que
     * portent les comptages, et les changer aurait déplacé l'historique d'une
     * boutique à l'autre.
     */
    public function testLaModificationNeTouchePasAuCodeNiALIdentifiant(): void
    {
        $modifiee = self::boutique()->with(name: 'Wrocław Rynek 2', active: false);

        self::assertSame('shop-1', $modifiee->id);
        self::assertSame('BOUTIQUE_1', $modifiee->code);
        self::assertSame('Wrocław Rynek 2', $modifiee->name);
        self::assertFalse($modifiee->active);
        // Ce qu'on n'a pas donné ne bouge pas.
        self::assertSame('Rynek 12', $modifiee->address);
        self::assertSame(51.1097, $modifiee->latitude);
    }

    public function testUneBoutiqueEstOuverteParDefaut(): void
    {
        self::assertTrue((new Shop('s', 'S', 'X'))->active);
    }
}
