<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Adapter\ShopPerformance;
use Merisu\Inventory\Domain\RankingMetric;
use Merisu\Inventory\Domain\SalesSummary;
use Merisu\Inventory\Domain\ShopRanking;
use PHPUnit\Framework\TestCase;

final class SalesSummaryTest extends TestCase
{
    /** @return list<ShopPerformance> */
    private static function reseau(): array
    {
        return [
            new ShopPerformance('a', 'Centrum', 'PL', 48200.0, 3120, 4180, 'PLN'),
            new ShopPerformance('b', 'Galeria', 'PL', 39750.0, 2740, 3390, 'PLN'),
            new ShopPerformance('c', 'Navigli', 'IT', 12800.0, 1180, 1640, 'EUR'),
        ];
    }

    public function testLaBoutiqueDuPosteEstRetrouveeEtSituee(): void
    {
        $resume = SalesSummary::of(self::reseau(), 'b');

        self::assertNotNull($resume->shop);
        self::assertSame('Galeria', $resume->shop->name);
        self::assertSame(2, $resume->rank);
        self::assertSame(3, $resume->shopCount);
        self::assertSame(4180 + 3390 + 1640, $resume->networkTiramisu);
    }

    /**
     * Un poste dont la caisse ignore la boutique doit rester affichable : le
     * total du réseau garde un sens, la fiche boutique disparaît.
     */
    public function testUneBoutiqueInconnueNAnnulePasLeReseau(): void
    {
        $resume = SalesSummary::of(self::reseau(), 'inexistante');

        self::assertNull($resume->shop);
        self::assertNull($resume->rank);
        self::assertNull($resume->shareOfNetwork());
        self::assertSame(9210, $resume->networkTiramisu);
    }

    public function testAucuneBoutiqueCouranteDemandee(): void
    {
        $resume = SalesSummary::of(self::reseau(), null);

        self::assertNull($resume->shop);
        self::assertNull($resume->rank);
    }

    public function testLaPartDuReseauEstUnPourcentage(): void
    {
        $resume = SalesSummary::of(self::reseau(), 'a');

        self::assertNotNull($resume->shareOfNetwork());
        self::assertEqualsWithDelta(4180 / 9210 * 100, $resume->shareOfNetwork(), 0.001);
    }

    /**
     * Réseau qui n'a rien vendu : « 0 % » ferait croire à une contre-performance
     * alors qu'il n'y a tout simplement rien à mesurer.
     */
    public function testUnReseauSansVenteNAPasDePart(): void
    {
        $resume = SalesSummary::of(
            [new ShopPerformance('a', 'Centrum', 'PL', 0.0, 0, 0, 'PLN')],
            'a',
        );

        self::assertNull($resume->shareOfNetwork());
        self::assertSame(0, $resume->networkTiramisu);
    }

    public function testLeReseauVideNeCasseRien(): void
    {
        $resume = SalesSummary::of([], 'a');

        self::assertNull($resume->shop);
        self::assertSame(0, $resume->shopCount);
        self::assertSame(0, $resume->networkTiramisu);
        self::assertSame([], SalesSummary::bars([]));
    }

    /** Les barres se mesurent sur la plus forte valeur, pas sur le total. */
    public function testLaPlusForteBarreEstPleine(): void
    {
        $barres = SalesSummary::bars(
            ShopRanking::build(self::reseau(), RankingMetric::TiramisuSold, 'b'),
        );

        self::assertCount(3, $barres);
        self::assertEqualsWithDelta(100.0, $barres[0]['percent'], 0.001);
        self::assertEqualsWithDelta(3390 / 4180 * 100, $barres[1]['percent'], 0.001);
        self::assertEqualsWithDelta(1640 / 4180 * 100, $barres[2]['percent'], 0.001);
        self::assertTrue($barres[1]['isCurrent']);
    }

    /**
     * Toutes les valeurs à zéro : les barres restent vides. Une division par le
     * maximum les remplirait toutes, et le réseau paraîtrait à l'équilibre
     * alors qu'il n'a rien vendu.
     */
    public function testDesValeursToutesNullesDonnentDesBarresVides(): void
    {
        $barres = SalesSummary::bars(ShopRanking::build(
            [
                new ShopPerformance('a', 'A', 'PL', 0.0, 0, 0, 'PLN'),
                new ShopPerformance('b', 'B', 'PL', 0.0, 0, 0, 'PLN'),
            ],
            RankingMetric::TiramisuSold,
        ));

        foreach ($barres as $barre) {
            self::assertSame(0.0, $barre['percent']);
        }
    }

    /**
     * Une valeur négative — un avoir massif remonté par la caisse — ne doit pas
     * produire une barre qui déborde à gauche.
     */
    public function testUneValeurNegativeNeDonnePasDeBarreNegative(): void
    {
        $barres = SalesSummary::bars(ShopRanking::build(
            [
                new ShopPerformance('a', 'A', 'PL', 0.0, 0, 100, 'PLN'),
                new ShopPerformance('b', 'B', 'PL', 0.0, 0, -40, 'PLN'),
            ],
            RankingMetric::TiramisuSold,
        ));

        foreach ($barres as $barre) {
            self::assertGreaterThanOrEqual(0.0, $barre['percent']);
            self::assertLessThanOrEqual(100.0, $barre['percent']);
        }
    }
}
