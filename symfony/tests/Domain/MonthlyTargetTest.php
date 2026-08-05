<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\MonthlyTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La jauge s'affiche en tête de l'écran Réseau : elle sera lue tous les jours,
 * par toute l'équipe. Deux façons de la rendre nuisible — tomber sur un
 * objectif à zéro, et faire déborder la barre quand l'objectif est dépassé.
 */
final class MonthlyTargetTest extends TestCase
{
    public function testSansObjectifIlNyAPasDeJauge(): void
    {
        // Réglage laissé vide en administration : rien à montrer, et surtout
        // aucune division par zéro.
        self::assertNull(MonthlyTarget::of(120, 0, 10, 31));
        self::assertNull(MonthlyTarget::of(120, -50, 10, 31));
    }

    public function testAvancementSimple(): void
    {
        $jauge = MonthlyTarget::of(250, 1000, 10, 31);

        self::assertNotNull($jauge);
        self::assertSame(25, $jauge->percent);
        self::assertSame(25, $jauge->barPercent);
        self::assertFalse($jauge->reached);
        self::assertSame(750, $jauge->remaining());
    }

    /**
     * Le cas qui ferait sortir la barre de sa boîte : la LARGEUR se plafonne,
     * le CHIFFRE non. Une équipe à 130 % a le droit de le lire.
     */
    public function testUnObjectifDepasseNeFaitPasDeborderLaBarre(): void
    {
        $jauge = MonthlyTarget::of(1300, 1000, 28, 31);

        self::assertNotNull($jauge);
        self::assertSame(130, $jauge->percent);
        self::assertSame(100, $jauge->barPercent);
        self::assertTrue($jauge->reached);
        self::assertSame(0, $jauge->remaining());
    }

    public function testObjectifExactementAtteint(): void
    {
        $jauge = MonthlyTarget::of(1000, 1000, 31, 31);

        self::assertNotNull($jauge);
        self::assertTrue($jauge->reached);
        self::assertSame(0, $jauge->remaining());
    }

    /**
     * Le repère de rythme, qui donne son sens au chiffre : 600 sur 1 000 est
     * excellent le 10 du mois et inquiétant le 28.
     */
    #[DataProvider('rythmes')]
    public function testLeRythmeSeCompareAuCalendrier(
        int $vendus,
        int $jour,
        int $joursDuMois,
        bool $dansLesTemps,
    ): void {
        $jauge = MonthlyTarget::of($vendus, 1000, $jour, $joursDuMois);

        self::assertNotNull($jauge);
        self::assertSame($dansLesTemps, $jauge->onTrack);
    }

    public static function rythmes(): iterable
    {
        yield 'en avance le 10' => [600, 10, 31, true];
        yield 'en retard le 28' => [600, 28, 31, false];
        yield 'pile sur le rythme' => [500, 15, 30, true];
        yield 'premier jour, rien vendu' => [0, 1, 31, false];
        yield 'dernier jour, objectif atteint' => [1000, 31, 31, true];
    }

    public function testUneVenteNegativeEstRameneeAZero(): void
    {
        // L'adaptateur de caisse est un module tiers : rien ne garantit qu'un
        // avoir massif ne fasse pas passer le total sous zéro un jour.
        $jauge = MonthlyTarget::of(-40, 1000, 10, 31);

        self::assertNotNull($jauge);
        self::assertSame(0, $jauge->sold);
        self::assertSame(0, $jauge->percent);
        self::assertSame(1000, $jauge->remaining());
    }

    public function testUnCalendrierAberrantNeCassePas(): void
    {
        // Jour hors du mois, mois de longueur nulle : la jauge s'affiche
        // quand même, quitte à borner. Un écran de consultation ne doit pas
        // tomber pour une date fantaisiste.
        $jauge = MonthlyTarget::of(500, 1000, 99, 31);
        self::assertNotNull($jauge);
        self::assertSame(100, $jauge->monthElapsed);

        $degenere = MonthlyTarget::of(500, 1000, 1, 0);
        self::assertNotNull($degenere);
        self::assertSame(100, $degenere->monthElapsed);
    }

    public function testLaPartDuMoisEcouleeResteDansSesBornes(): void
    {
        foreach ([28, 29, 30, 31] as $joursDuMois) {
            foreach (range(1, $joursDuMois) as $jour) {
                $jauge = MonthlyTarget::of(1, 1000, $jour, $joursDuMois);

                self::assertNotNull($jauge);
                self::assertGreaterThanOrEqual(0, $jauge->monthElapsed);
                self::assertLessThanOrEqual(100, $jauge->monthElapsed);
            }
        }
    }
}
