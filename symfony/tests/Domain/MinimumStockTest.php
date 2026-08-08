<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\MinimumStock;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\RoundingMode;
use Merisu\Inventory\Domain\WeatherKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MinimumStockTest extends TestCase
{
    private static function produit(float $step = 1.0, RoundingMode $mode = RoundingMode::Ceil): Product
    {
        return new Product('p1', 'P1', ['fr' => 'Tiramisu'], 'pcs', true, 0.0, $step, $mode, null, 1);
    }

    // ── La moyenne ──────────────────────────────────────────────────────────

    public function testLaMoyennePorteSurLesSixDerniersReleves(): void
    {
        $m = MinimumStock::of([10.0, 20.0, 30.0, 10.0, 20.0, 30.0], 0.0, self::produit());

        self::assertNotNull($m);
        self::assertSame(20.0, $m->average);
        self::assertSame(6, $m->samples);
        self::assertSame(0, $m->skipped);
    }

    /** Au-delà de six semaines, la saison a changé : la moyenne parlerait
     *  d'une autre boutique. */
    public function testAuDelaDeSixSemainesRienNEstRegarde(): void
    {
        $m = MinimumStock::of([10.0, 10.0, 10.0, 10.0, 10.0, 10.0, 1000.0, 1000.0], 0.0, self::produit());

        self::assertNotNull($m);
        self::assertSame(10.0, $m->average);
        self::assertSame(6, $m->samples);
    }

    /**
     * Une boutique fermée le dimanche laisse un trou. Le compter pour zéro
     * diviserait la moyenne par deux et le rayon serait vide le dimanche
     * suivant.
     */
    public function testUnJourSansComptageEstEcarteEtNonComptePourZero(): void
    {
        $m = MinimumStock::of([30.0, null, 30.0, null, 30.0, null], 0.0, self::produit());

        self::assertNotNull($m);
        self::assertSame(30.0, $m->average);
        self::assertSame(3, $m->samples);
        self::assertSame(3, $m->skipped);
    }

    /** Un écoulé négatif est une donnée aberrante, pas une vente à l'envers. */
    public function testUnReleveNegatifEstEcarte(): void
    {
        $m = MinimumStock::of([20.0, -5.0, 20.0], 0.0, self::produit());

        self::assertNotNull($m);
        self::assertSame(20.0, $m->average);
        self::assertSame(1, $m->skipped);
    }

    /** Sans aucun relevé, il n'y a rien à dire — et surtout pas zéro. */
    public function testSansAucunReleveIlNYAPasDeMinimum(): void
    {
        self::assertNull(MinimumStock::of([], 0.0, self::produit()));
        self::assertNull(MinimumStock::of([null, null, null], 0.0, self::produit()));
    }

    /** Un écoulé nul EST une information : personne n'en a pris. */
    public function testUnEcouleNulResteUnReleve(): void
    {
        $m = MinimumStock::of([0.0, 0.0], 0.0, self::produit());

        self::assertNotNull($m);
        self::assertSame(0.0, $m->average);
        self::assertSame(2, $m->samples);
    }

    // ── La correction météo ─────────────────────────────────────────────────

    /** @return iterable<string, array{float, float}> */
    public static function corrections(): iterable
    {
        yield 'pluie, +5 %' => [5.0, 105.0];
        yield 'soleil, −9 %' => [-9.0, 91.0];
        yield 'temps ordinaire' => [0.0, 100.0];
        yield 'forte hausse' => [50.0, 150.0];
    }

    #[DataProvider('corrections')]
    public function testLaCorrectionMeteoSAppliqueALaMoyenne(float $pct, float $attendu): void
    {
        $m = MinimumStock::of([100.0, 100.0, 100.0], $pct, self::produit(mode: RoundingMode::None));

        self::assertNotNull($m);
        self::assertSame(100.0, $m->average);
        self::assertEqualsWithDelta($attendu, $m->adjusted, 0.000001);
    }

    /**
     * Un réglage aberrant en administration — « −250 % » — ne doit pas vider
     * le rayon en produisant un minimum négatif.
     */
    public function testUneCorrectionAberranteNeDonneJamaisUnMinimumNegatif(): void
    {
        $m = MinimumStock::of([100.0, 100.0], -250.0, self::produit());

        self::assertNotNull($m);
        self::assertSame(0.0, $m->adjusted);
        self::assertSame(0.0, $m->value);
    }

    // ── L'arrondi ───────────────────────────────────────────────────────────

    /** Un minimum de 7,3 barquettes ne veut rien dire au comptoir. */
    public function testLeMinimumSuitLArrondiDuProduit(): void
    {
        // Moyenne 20, +5 % = 21 → multiple de 6 supérieur → 24.
        $m = MinimumStock::of([20.0, 20.0, 20.0], 5.0, self::produit(step: 6.0));

        self::assertNotNull($m);
        self::assertSame(24.0, $m->value);
    }

    public function testSansArrondiLesDecimalesSurvivent(): void
    {
        $m = MinimumStock::of([10.0, 11.0], -9.0, self::produit(mode: RoundingMode::None));

        self::assertNotNull($m);
        self::assertEqualsWithDelta(10.5 * 0.91, $m->value, 0.000001);
    }

    // ── Fiabilité ───────────────────────────────────────────────────────────

    /**
     * Deux relevés font une moyenne que le premier jour de soldes suffit à
     * fausser. Le seuil manuel a été posé par quelqu'un qui connaît la
     * boutique : il vaut mieux qu'une moyenne bâtie sur presque rien.
     */
    public function testEnDessousDeTroisRelevesLeCalculNeFaitPasFoi(): void
    {
        self::assertFalse(MinimumStock::of([10.0], 0.0, self::produit())->isReliable());
        self::assertFalse(MinimumStock::of([10.0, 10.0], 0.0, self::produit())->isReliable());
        self::assertTrue(MinimumStock::of([10.0, 10.0, 10.0], 0.0, self::produit())->isReliable());
    }

    // ── Les corrections de départ ───────────────────────────────────────────

    /** Deux valeurs viennent de la boutique ; les autres partent à zéro,
     *  c'est-à-dire « aucun effet déclaré » et non « effet nul mesuré ». */
    public function testLesCorrectionsDeDepartSontCellesDeLaBoutique(): void
    {
        self::assertSame(-9.0, WeatherKind::Sunny->defaultPercent());
        self::assertSame(5.0, WeatherKind::Rain->defaultPercent());
        self::assertSame(0.0, WeatherKind::Cloudy->defaultPercent());
        self::assertSame(0.0, WeatherKind::Snow->defaultPercent());
    }

    public function testLeTempsOrdinaireEstLaReference(): void
    {
        self::assertSame(WeatherKind::Cloudy, WeatherKind::default());
        self::assertSame(WeatherKind::Cloudy, WeatherKind::fromLoose('BROUILLARD'));
        self::assertSame(WeatherKind::Rain, WeatherKind::fromLoose('rain'));
        self::assertSame(WeatherKind::Cloudy, WeatherKind::fromLoose(null));
    }
}
