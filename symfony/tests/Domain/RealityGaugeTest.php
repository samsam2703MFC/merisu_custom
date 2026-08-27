<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\RealityGauge;
use PHPUnit\Framework\TestCase;

/** La géométrie de la jauge — les extrémités et le remplissage. */
final class RealityGaugeTest extends TestCase
{
    public function testLesExtremitesReposentSurLaLigneDeBase(): void
    {
        $g = RealityGauge::build(0.0);

        // Vide : l'aiguille est à gauche, sur la base (y = cy), à x = cx − r.
        self::assertEqualsWithDelta($g->cx - $g->r, $g->needleX, 0.05);
        self::assertEqualsWithDelta($g->cy, $g->needleY, 0.05);
        self::assertSame(0.0, $g->fillLength);
    }

    public function testPleinPorteLAiguilleADroite(): void
    {
        $g = RealityGauge::build(1.0);

        self::assertEqualsWithDelta($g->cx + $g->r, $g->needleX, 0.05);
        self::assertEqualsWithDelta($g->cy, $g->needleY, 0.05);
        self::assertSame(100.0, $g->fillLength);
    }

    public function testAMoitieLAiguilleEstAuSommet(): void
    {
        $g = RealityGauge::build(0.5);

        self::assertEqualsWithDelta($g->cx, $g->needleX, 0.05);
        self::assertEqualsWithDelta($g->cy - $g->r, $g->needleY, 0.05);
        self::assertSame(50.0, $g->fillLength);
    }

    public function testLeRemplissageEstBorneEntreZeroEtCent(): void
    {
        self::assertSame(0.0, RealityGauge::build(-1.0)->fillLength);
        self::assertSame(100.0, RealityGauge::build(9.0)->fillLength);
    }

    public function testLesRepereDeSeuilSontPosesSurLArc(): void
    {
        $g = RealityGauge::build(0.3, [0.125, 0.375]);

        self::assertCount(2, $g->ticks);
        self::assertArrayHasKey('x1', $g->ticks[0]);
        self::assertArrayHasKey('y2', $g->ticks[1]);
    }

    public function testLArcEstUnCheminSvgValide(): void
    {
        $g = RealityGauge::build(0.2);

        self::assertStringStartsWith('M ', $g->arc);
        self::assertStringContainsString(' A ', $g->arc);
    }
}
