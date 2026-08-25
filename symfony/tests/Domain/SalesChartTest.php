<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\SalesBucket;
use Merisu\Inventory\Domain\SalesChart;
use Merisu\Inventory\Domain\SalesTrend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SalesChartTest extends TestCase
{
    /** @param list<float> $valeurs */
    private static function cases(array $valeurs): array
    {
        $cases = [];
        foreach ($valeurs as $i => $v) {
            $cases[] = new SalesBucket('c' . $i, $v, $v * 10, 1);
        }

        return $cases;
    }

    // ── L'échelle ───────────────────────────────────────────────────────────

    #[DataProvider('sommets')]
    public function testLeSommetSArrondiAUnNombreQuOnLit(float $brut, float $attendu): void
    {
        self::assertSame($attendu, SalesChart::niceMax($brut));
    }

    /** @return iterable<string, array{float, float}> */
    public static function sommets(): iterable
    {
        yield 'cent dix-neuf' => [119.0, 120.0];
        yield 'mille huit cent quarante-trois' => [1843.0, 2000.0];
        yield 'sept' => [7.0, 8.0];
        yield 'vingt-trois' => [23.0, 25.0];
        yield 'quarante-deux' => [42.0, 50.0];
        // Le palier fin : sans lui, 119 montait à 200 et les barres
        // n'atteignaient plus que six dixièmes du cadre.
        yield 'cent quatorze' => [114.0, 120.0];
        yield 'trois cent dix' => [310.0, 400.0];
        yield 'exactement cent' => [100.0, 100.0];
        // Zéro n'a pas d'échelle : un maximum nul diviserait tout par zéro.
        yield 'rien vendu' => [0.0, 1.0];
    }

    /**
     * L'échelle part TOUJOURS de zéro.
     *
     * Une barre tronquée à mi-hauteur transforme un écart de 5 % en montagne.
     * C'est le mensonge le plus courant des graphiques d'affaires, et il n'a
     * pas sa place là où l'on décide combien produire.
     */
    public function testLEchellePartDeZero(): void
    {
        $graduations = SalesChart::ticks(119.0);

        self::assertSame(0.0, $graduations[0]['value']);
        self::assertSame((float) (SalesChart::TOP + SalesChart::PLOT), $graduations[0]['y']);
        self::assertSame(120.0, end($graduations)['value']);
        self::assertSame((float) SalesChart::TOP, end($graduations)['y']);
    }

    /**
     * Deux fois plus vendu, deux fois plus haut.
     *
     * Une barre est une AFFIRMATION sur des chiffres : si la hauteur ne suit
     * pas la valeur, le dessin ment avec l'aplomb d'une mesure.
     */
    public function testLaHauteurEstProportionnelleALaValeur(): void
    {
        [$petite, $grande] = SalesChart::columns(self::cases([50.0, 100.0]));

        self::assertEqualsWithDelta($grande['height'] / 2, $petite['height'], 0.001);
    }

    // ── Les colonnes ────────────────────────────────────────────────────────

    /**
     * La barre ne remplit JAMAIS sa case.
     *
     * L'air entre les barres fait autant pour la lecture que les barres :
     * remplies, elles donnent un mur qu'on ne déchiffre plus.
     */
    public function testLaBarreNeRemplitJamaisSaCase(): void
    {
        foreach ([2, 5, 7, 12] as $nombre) {
            $colonnes = SalesChart::columns(self::cases(array_fill(0, $nombre, 10.0)));
            $bande = SalesChart::WIDTH / $nombre;

            foreach ($colonnes as $barre) {
                self::assertLessThanOrEqual(SalesChart::BAR_MAX, $barre['width'], "$nombre colonnes");
                self::assertLessThan($bande, $barre['width'], "$nombre colonnes");
            }
        }
    }

    /** Les barres restent dans le cadre, et se suivent sans se chevaucher. */
    public function testLesBarresTiennentDansLeCadreEtNeSeChevauchentPas(): void
    {
        $colonnes = SalesChart::columns(self::cases([10.0, 40.0, 25.0, 5.0, 30.0, 12.0, 8.0]));

        $droiteePrecedente = -1.0;
        foreach ($colonnes as $barre) {
            self::assertGreaterThanOrEqual(0, $barre['x']);
            self::assertLessThanOrEqual(SalesChart::WIDTH, $barre['x'] + $barre['width']);
            self::assertGreaterThanOrEqual(SalesChart::TOP, $barre['y']);
            self::assertGreaterThan($droiteePrecedente, $barre['x']);
            $droiteePrecedente = $barre['x'] + $barre['width'];
        }
    }

    /** Une case à zéro n'a pas de hauteur, mais garde sa place. */
    public function testUneCaseAZeroGardeSaPlace(): void
    {
        $colonnes = SalesChart::columns(self::cases([0.0, 100.0]));

        self::assertSame(0.0, $colonnes[0]['height']);
        self::assertSame((float) (SalesChart::TOP + SalesChart::PLOT), $colonnes[0]['y']);
    }

    public function testUnJeuVideNeDessineRien(): void
    {
        self::assertSame([], SalesChart::columns([]));
        self::assertSame(['line' => '', 'area' => '', 'points' => []], SalesChart::area([]));
    }

    // ── La courbe ───────────────────────────────────────────────────────────

    public function testLaCourbeSEtendDUnBordALAutre(): void
    {
        $tracé = SalesChart::area(self::cases([10.0, 20.0, 15.0, 30.0]));

        // Le tracé garde une marge de chaque côté : le disque du dernier
        // point et sa valeur déborderaient sinon du cadre.
        self::assertCount(4, $tracé['points']);
        self::assertSame((float) SalesChart::PAD_X, $tracé['points'][0]['x']);
        self::assertSame((float) (SalesChart::WIDTH - SalesChart::PAD_X), end($tracé['points'])['x']);
    }

    /**
     * L'aplat se referme sur la ligne de base.
     *
     * Sans cela, le remplissage remonterait jusqu'au haut du cadre et l'on
     * verrait le négatif de la courbe.
     */
    public function testLAplatSeRefermeSurLaLigneDeBase(): void
    {
        $tracé = SalesChart::area(self::cases([10.0, 30.0]));
        $base = SalesChart::TOP + SalesChart::PLOT;

        self::assertStringStartsWith('M' . SalesChart::PAD_X . ',' . $base, $tracé['area']);
        self::assertStringEndsWith(',' . $base . ' Z', $tracé['area']);
    }

    /** Un point unique ne divise pas par zéro : il se pose au milieu. */
    public function testUnPointUniqueSePoseAuMilieu(): void
    {
        $tracé = SalesChart::area(self::cases([12.0]));

        self::assertCount(1, $tracé['points']);
        self::assertSame(SalesChart::WIDTH / 2, (int) $tracé['points'][0]['x']);
    }

    // ── La tendance ─────────────────────────────────────────────────────────

    public function testLaVariationSeCompteEnPourcentage(): void
    {
        self::assertSame(10.0, SalesTrend::change(110.0, 100.0));
        self::assertSame(-25.0, SalesTrend::change(75.0, 100.0));
        self::assertSame(0.0, SalesTrend::change(100.0, 100.0));
    }

    /**
     * On ne progresse pas « de x % » depuis rien.
     *
     * Rendre +100 % aurait affiché une envolée le lendemain de l'installation,
     * quand la seule nouveauté est qu'on a commencé à relever.
     */
    public function testDepuisRienIlNYAPasDeVariation(): void
    {
        self::assertNull(SalesTrend::change(100.0, 0.0));
        self::assertNull(SalesTrend::change(100.0, -5.0));
    }

    /** La période de référence a la MÊME longueur, et s'arrête la veille. */
    public function testLaPeriodeDeReferenceALaMemeLongueur(): void
    {
        $avant = SalesTrend::previous('2026-07-15', '2026-08-25');

        self::assertSame('2026-06-03', $avant['from']);
        self::assertSame('2026-07-14', $avant['to']);
    }

    public function testUneSeuleJourneeSeCompareALaVeille(): void
    {
        self::assertSame(
            ['from' => '2026-08-24', 'to' => '2026-08-24'],
            SalesTrend::previous('2026-08-25', '2026-08-25'),
        );
    }
}
