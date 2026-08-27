<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\RealityCheck;
use Merisu\Inventory\Domain\RealitySeverity;
use PHPUnit\Framework\TestCase;

/**
 * Le contrôle de réalité : réel vs théorique, et la jauge qui en sort.
 *
 * Les cas qui comptent sont les bords — pas de relevé, théorique nul,
 * dérive dans un sens comme dans l'autre — parce que c'est là qu'un tableau
 * de bord ment sans le dire.
 */
final class RealityCheckTest extends TestCase
{
    private const TOL = 0.05;

    public function testAucunReleveResteInconnuEtNonRassurant(): void
    {
        $r = RealityCheck::of(120.0, null, self::TOL);

        self::assertFalse($r->hasReal());
        self::assertSame(RealitySeverity::Unknown, $r->severity);
        self::assertNull($r->deviationRatio);
        self::assertNull($r->deviationPercent());
        self::assertSame(0.0, $r->fill);
    }

    public function testReelEgalTheoriqueEstParfait(): void
    {
        $r = RealityCheck::of(100.0, 100.0, self::TOL);

        self::assertSame(RealitySeverity::Ok, $r->severity);
        self::assertSame(0.0, $r->deviationRatio);
        self::assertSame(0.0, $r->fill);
    }

    public function testUnePetiteDeriveResteDansLaTolerance(): void
    {
        // 4 % de surconsommation, sous les 5 % admis.
        $r = RealityCheck::of(100.0, 104.0, self::TOL);

        self::assertSame(RealitySeverity::Ok, $r->severity);
        self::assertEqualsWithDelta(0.04, $r->deviationRatio, 1e-9);
        self::assertEqualsWithDelta(0.04 / 0.40, $r->fill, 1e-9);
    }

    public function testUneDeriveMoyenneAlerte(): void
    {
        // 12 % : au-dessus de la tolérance, sous trois fois.
        $r = RealityCheck::of(100.0, 112.0, self::TOL);

        self::assertSame(RealitySeverity::Warn, $r->severity);
    }

    public function testUneGrosseDeriveEstEnDanger(): void
    {
        // 25 % : au-delà de trois fois la tolérance.
        $r = RealityCheck::of(100.0, 125.0, self::TOL);

        self::assertSame(RealitySeverity::Danger, $r->severity);
        self::assertEqualsWithDelta(0.25 / 0.40, $r->fill, 1e-9);
    }

    /**
     * Le point du cahier des charges : la jauge se remplit dans les DEUX sens.
     *
     * Consommer 25 % de MOINS que la recette n'est pas une économie, c'est un
     * relevé qui ment — on ne fait pas un tiramisu avec la moitié de sa crème.
     * La dérive est la même que 25 % de trop.
     */
    public function testLaSousConsommationDeriveAutantQueLaSur(): void
    {
        $trop = RealityCheck::of(100.0, 125.0, self::TOL);
        $pasAssez = RealityCheck::of(100.0, 75.0, self::TOL);

        self::assertSame($trop->severity, $pasAssez->severity);
        self::assertEqualsWithDelta($trop->fill, $pasAssez->fill, 1e-9);
        // Mais le SIGNE, lui, distingue les deux : le tableau doit savoir de
        // quel côté chercher.
        self::assertGreaterThan(0, $trop->deviationRatio);
        self::assertLessThan(0, $pasAssez->deviationRatio);
    }

    public function testLaJaugeSaturationAQuarantePourCent(): void
    {
        // 80 % de dérive : la jauge est pleine, pas au-delà.
        $r = RealityCheck::of(100.0, 180.0, self::TOL);

        self::assertSame(1.0, $r->fill);
        self::assertSame(RealitySeverity::Danger, $r->severity);
        // Le pourcentage réel reste lisible même quand la jauge sature.
        self::assertEqualsWithDelta(80.0, $r->deviationPercent(), 1e-9);
    }

    /**
     * Théorique nul mais réel non nul : consommé sans vente qui l'explique.
     * Pas de pourcentage (division par zéro), mais la dérive est maximale —
     * c'est le pire cas, jamais un cas vide.
     */
    public function testConsommerSansVentesEstLePireCas(): void
    {
        $r = RealityCheck::of(0.0, 40.0, self::TOL);

        self::assertNull($r->deviationRatio);
        self::assertSame(1.0, $r->fill);
        self::assertSame(RealitySeverity::Danger, $r->severity);
        self::assertSame(40.0, $r->deviation);
    }

    public function testNiVenteNiConsommationEstNeutre(): void
    {
        $r = RealityCheck::of(0.0, 0.0, self::TOL);

        self::assertSame(RealitySeverity::Ok, $r->severity);
        self::assertSame(0.0, $r->fill);
    }
}
