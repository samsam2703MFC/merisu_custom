<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\WeatherSalesAnalysis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeatherSalesAnalysisTest extends TestCase
{
    /**
     * @param list<array{string, float}> $jours date, unités
     *
     * @return array<string, array{units: float, revenue: float}>
     */
    private static function ventes(array $jours): array
    {
        $ventes = [];

        foreach ($jours as [$date, $unites]) {
            $ventes[$date] = ['units' => $unites, 'revenue' => $unites * 20.0];
        }

        return $ventes;
    }

    /** Cinq jours de suite à partir du 1er, tous sous la même clé. */
    private static function serie(string $cle, int $debut, int $combien, float $unites): array
    {
        $ventes = [];
        $cles = [];

        for ($i = 0; $i < $combien; $i++) {
            $date = sprintf('2026-06-%02d', $debut + $i);
            $ventes[$date] = ['units' => $unites, 'revenue' => $unites * 20.0];
            $cles[$date] = $cle;
        }

        return [$ventes, $cles];
    }

    public function testLEcartSeMesureEnJourneesPasEnTotaux(): void
    {
        // CHAUD : 5 jours à 120. DOUX : 15 jours à 100.
        // Le total de DOUX est le plus gros, mais c'est CHAUD qui vend le plus
        // par jour — et c'est ce chiffre-là qui règle un stock minimum.
        [$v1, $c1] = self::serie('CHAUD', 1, 5, 120.0);
        [$v2, $c2] = self::serie('DOUX', 6, 15, 100.0);

        $analyse = WeatherSalesAnalysis::build($v1 + $v2, $c1 + $c2, ['CHAUD', 'DOUX']);

        // Référence : (5×120 + 15×100) / 20 = 105
        self::assertSame(105.0, $analyse->reference);
        self::assertSame(20, $analyse->observedDays);

        [$chaud, $doux] = $analyse->rows;

        self::assertSame(120.0, $chaud->averageUnits);
        self::assertSame(100.0, $doux->averageUnits);

        // (120 − 105) / 105 = +14,3 % ; (100 − 105) / 105 = −4,8 %
        self::assertSame(14.3, $chaud->deviation);
        self::assertSame(-4.8, $doux->deviation);
    }

    /**
     * Le garde-fou qui compte : sous cinq journées, AUCUN pourcentage.
     *
     * Trois jours au-dessus de 30 °C, dont un 15 août, mesurent le 15 août.
     * Une fois posé dans les seuils, ce chiffre n'aurait plus rien qui rappelle
     * sur quoi il reposait, et il ferait produire pour de bon.
     */
    #[DataProvider('quantitesDeJours')]
    public function testUneConditionTropRareNeRendAucunEcart(int $jours, bool $attenduFiable): void
    {
        [$rares, $clesRares] = self::serie('RARE', 1, $jours, 300.0);
        [$courants, $clesCourants] = self::serie('COURANT', 10, 20, 100.0);

        $analyse = WeatherSalesAnalysis::build(
            $rares + $courants,
            $clesRares + $clesCourants,
            ['RARE', 'COURANT'],
        );

        $rare = $analyse->rows[0];

        self::assertSame($jours, $rare->days);
        self::assertSame($attenduFiable, $rare->isReliable());

        // La MOYENNE reste affichée : elle est vraie, elle ne prétend rien.
        // C'est le POURCENTAGE qu'on refuse, parce qu'on le reporterait.
        self::assertSame(300.0, $rare->averageUnits);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function quantitesDeJours(): iterable
    {
        yield 'une seule journée' => [1, false];
        yield 'quatre journées' => [4, false];
        yield 'cinq journées, le seuil' => [5, true];
        yield 'dix journées' => [10, true];
    }

    /**
     * Une journée sans météo au journal n'entre nulle part — pas même dans la
     * référence. La compter dans la moyenne générale sans pouvoir la ranger
     * dans une tranche fausserait les deux côtés de l'écart.
     */
    public function testUneJourneeSansMeteoEstEcarteeDesDeuxCotes(): void
    {
        [$ventes, $cles] = self::serie('DOUX', 1, 5, 100.0);

        // Une journée énorme, mais absente du journal météo.
        $ventes['2026-06-20'] = ['units' => 10000.0, 'revenue' => 200000.0];

        $analyse = WeatherSalesAnalysis::build($ventes, $cles, ['DOUX']);

        self::assertSame(5, $analyse->observedDays);
        self::assertSame(100.0, $analyse->reference);
        self::assertSame(0.0, $analyse->rows[0]->deviation);
    }

    public function testUneConditionJamaisObserveeResteAZeroSansEcart(): void
    {
        [$ventes, $cles] = self::serie('DOUX', 1, 6, 100.0);

        $analyse = WeatherSalesAnalysis::build($ventes, $cles, ['DOUX', 'NEIGE']);

        $neige = $analyse->rows[1];

        self::assertSame('NEIGE', $neige->key);
        self::assertSame(0, $neige->days);
        self::assertSame(0.0, $neige->averageUnits);
        self::assertNull($neige->deviation);
        self::assertSame('—', $neige->deviationLabel());
    }

    /** Aucune vente du tout : pas de division par une référence nulle. */
    public function testSansAucuneVenteRienNExplose(): void
    {
        $analyse = WeatherSalesAnalysis::build([], [], ['DOUX']);

        self::assertSame(0.0, $analyse->reference);
        self::assertSame(0, $analyse->observedDays);
        self::assertNull($analyse->rows[0]->deviation);
        self::assertFalse($analyse->hasAnythingToApply());
    }

    public function testSeulsLesEcartsFiablesSontReportables(): void
    {
        [$rares, $clesRares] = self::serie('RARE', 1, 2, 300.0);
        [$courants, $clesCourants] = self::serie('COURANT', 5, 20, 100.0);

        $analyse = WeatherSalesAnalysis::build(
            $rares + $courants,
            $clesRares + $clesCourants,
            ['RARE', 'COURANT'],
        );

        self::assertTrue($analyse->hasAnythingToApply());
        self::assertArrayNotHasKey('RARE', $analyse->reliableDeviations());
        self::assertArrayHasKey('COURANT', $analyse->reliableDeviations());
    }

    #[DataProvider('ecritures')]
    public function testLEcartSEcritAvecSonSigne(?float $ecart, string $attendu): void
    {
        $ligne = new \Merisu\Inventory\Domain\WeatherSalesRow('X', 10, 100.0, 2000.0, $ecart);

        self::assertSame($attendu, $ligne->deviationLabel());
    }

    /** @return iterable<string, array{?float, string}> */
    public static function ecritures(): iterable
    {
        yield 'hausse' => [19.4, '+19 %'];
        // Le signe moins TYPOGRAPHIQUE : le trait d'union se confond avec une
        // césure en fin de ligne.
        yield 'baisse' => [-7.2, '−7 %'];
        yield 'égalité franche' => [0.0, '='];
        yield 'écart négligeable' => [0.3, '='];
        yield 'absent' => [null, '—'];
    }
}
