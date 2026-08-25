<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\NetworkChart;
use PHPUnit\Framework\TestCase;

final class NetworkChartTest extends TestCase
{
    /** @return list<string> */
    private static function jours(int $n): array
    {
        $dates = [];
        $j = new \DateTimeImmutable('2026-08-01');

        for ($i = 0; $i < $n; $i++) {
            $dates[] = $j->format('Y-m-d');
            $j = $j->modify('+1 day');
        }

        return $dates;
    }

    public function testLeCumulNeDescendJamais(): void
    {
        $dates = self::jours(5);
        $chart = NetworkChart::build($dates, ['Wrocław' => array_combine($dates, [10, 0, 30, 5, 20])], null);

        self::assertSame([10.0, 10.0, 40.0, 45.0, 65.0], $chart->cumulative);
        self::assertSame(65.0, $chart->total);
        self::assertSame(13.0, $chart->average);
    }

    /**
     * L'échelle porte le cumul ET l'objectif : une droite d'objectif qui
     * sortirait du cadre ne dirait plus de combien on est en retard.
     */
    public function testUnObjectifPlusHautQueLesVentesTientDansLeCadre(): void
    {
        $dates = self::jours(3);
        $chart = NetworkChart::build($dates, ['A' => array_combine($dates, [10, 10, 10])], 300.0);

        self::assertSame(300.0, $chart->max);

        // Le dernier point de la droite d'objectif touche le haut du tracé.
        $fin = explode(' ', (string) $chart->targetLine)[1];
        self::assertSame((float) NetworkChart::TOP, (float) explode(',', $fin)[1]);
    }

    public function testSansObjectifIlNyAniDroiteNiRythme(): void
    {
        $dates = self::jours(3);
        $chart = NetworkChart::build($dates, ['A' => array_combine($dates, [10, 10, 10])], null);

        self::assertNull($chart->targetLine);
        self::assertNull($chart->pace());

        // Zéro pour objectif vaut « aucun objectif », pas « objectif manqué ».
        self::assertNull(NetworkChart::build($dates, ['A' => array_combine($dates, [1, 1, 1])], 0.0)->pace());
    }

    public function testLeRythmeSeLitEnPourcentageDeLObjectif(): void
    {
        $dates = self::jours(2);
        $chart = NetworkChart::build($dates, ['A' => array_combine($dates, [40, 40])], 100.0);

        self::assertSame(80.0, $chart->pace());
    }

    /**
     * Une boutique garde SA couleur : un filtre qui en retire une ne doit pas
     * repeindre les autres, sinon on croit lire une autre boutique.
     */
    public function testChaqueBoutiqueGardeSaCouleurDansLOrdre(): void
    {
        $dates = self::jours(2);
        $chart = NetworkChart::build($dates, [
            'Wrocław' => array_combine($dates, [1, 1]),
            'Warszawa' => array_combine($dates, [2, 2]),
            'Kraków' => array_combine($dates, [3, 3]),
        ], null);

        self::assertSame(NetworkChart::COLORS[0], $chart->colors['Wrocław']);
        self::assertSame(NetworkChart::COLORS[1], $chart->colors['Warszawa']);
        self::assertSame(NetworkChart::COLORS[2], $chart->colors['Kraków']);
        self::assertCount(3, $chart->series);
    }

    public function testUnePeriodeVideNExplosePas(): void
    {
        $chart = NetworkChart::build([], [], 500.0);

        self::assertSame([], $chart->bars());
        self::assertSame([], $chart->axisLabels());
        self::assertSame(0.0, $chart->total);
        self::assertSame('', $chart->cumulativePoints);
    }

    /** Une seule journée : le point se pose au milieu, pas sur le bord. */
    public function testUneSeuleJourneeSeCentre(): void
    {
        $chart = NetworkChart::build(['2026-08-01'], ['A' => ['2026-08-01' => 12.0]], null);

        self::assertSame((float) (NetworkChart::WIDTH / 2), $chart->xOf(0));
        self::assertCount(1, $chart->bars());
    }

    /**
     * Trente et une dates se chevauchent en un pavé illisible : on n'en garde
     * que cinq, dont toujours la première et la dernière.
     */
    public function testLAxeNeGardeQueCinqDatesDontLesDeuxBornes(): void
    {
        $dates = self::jours(31);
        $chart = NetworkChart::build($dates, ['A' => array_fill_keys($dates, 1.0)], null);

        $etiquettes = $chart->axisLabels();

        self::assertCount(5, $etiquettes);
        self::assertSame('2026-08-01', $etiquettes[0]);
        self::assertSame('2026-08-31', $etiquettes[30]);
    }

    public function testUnePeriodeCourteGardeToutesSesDates(): void
    {
        $dates = self::jours(4);
        $chart = NetworkChart::build($dates, ['A' => array_fill_keys($dates, 1.0)], null);

        self::assertCount(4, $chart->axisLabels());
    }

    /** Les barres restent dans le cadre, et laissent un fond entre elles. */
    public function testLesBarresTiennentDansLeCadre(): void
    {
        $dates = self::jours(7);
        $chart = NetworkChart::build($dates, ['A' => array_combine($dates, [10, 20, 30, 40, 50, 60, 70])], null);

        foreach ($chart->bars() as $b) {
            self::assertGreaterThanOrEqual(NetworkChart::PAD_X - 1, $b['x']);
            self::assertLessThanOrEqual(NetworkChart::WIDTH - NetworkChart::PAD_X + 1, $b['x'] + $b['w']);
            self::assertGreaterThanOrEqual(NetworkChart::TOP - 0.1, $b['y']);
            // Le pied repose EXACTEMENT sur la ligne de base : pas « à peu près ».
            self::assertSame((float) (NetworkChart::TOP + NetworkChart::PLOT), $b['y'] + $b['h']);
        }

        // La plus haute touche le sommet du tracé.
        self::assertSame((float) NetworkChart::TOP, $chart->bars()[6]['y']);
    }

    /** Une journée à zéro ne dessine pas de barre négative. */
    public function testUneJourneeAZeroNeDessinePasDeBarre(): void
    {
        $dates = self::jours(2);
        $chart = NetworkChart::build($dates, ['A' => array_combine($dates, [0, 10])], null);

        self::assertSame(0.0, $chart->bars()[0]['h']);
    }
}
