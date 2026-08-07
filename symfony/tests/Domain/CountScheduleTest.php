<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Domain\CountSchedule;
use Merisu\Inventory\Domain\DayOfWeek;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CountScheduleTest extends TestCase
{
    /** @param list<DayOfWeek> $jours */
    private static function noms(array $jours): array
    {
        return array_map(static fn (DayOfWeek $j): string => $j->value, $jours);
    }

    // ── Jours déduits de la fréquence ───────────────────────────────────────

    /** @return iterable<string, array{int, list<string>}> */
    public static function frequences(): iterable
    {
        yield 'une fois : le lundi' => [1, ['MON']];
        yield 'deux fois : lundi et jeudi' => [2, ['MON', 'THU']];
        yield 'trois fois : lundi, mercredi, vendredi' => [3, ['MON', 'WED', 'FRI']];
        yield 'quatre fois : lundi, mardi, jeudi, samedi' => [4, ['MON', 'TUE', 'THU', 'SAT']];
        yield 'cinq fois : la semaine ouvrée' => [5, ['MON', 'TUE', 'WED', 'THU', 'FRI']];
        yield 'sept fois : tous les jours' => [7, ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN']];
    }

    #[DataProvider('frequences')]
    public function testLaFrequenceDonneSesJours(int $frequence, array $attendus): void
    {
        $rythme = new CountSchedule(frequency: $frequence);

        self::assertSame($attendus, self::noms($rythme->days()));
    }

    /** Autant de jours que de comptages annoncés : le compte doit tomber juste. */
    #[DataProvider('frequences')]
    public function testIlYAAutantDeJoursQueLaFrequenceLAnnonce(int $frequence, array $attendus): void
    {
        self::assertCount($frequence, (new CountSchedule(frequency: $frequence))->days());
        self::assertCount($frequence, $attendus);
    }

    /** Jamais deux fois le même jour, quelle que soit la fréquence. */
    #[DataProvider('frequences')]
    public function testAucunJourNEstCompteDeuxFois(int $frequence): void
    {
        $jours = self::noms((new CountSchedule(frequency: $frequence))->days());

        self::assertSame($jours, array_values(array_unique($jours)));
    }

    /** Le lundi ouvre toujours la marche : c'est le premier jour de la semaine. */
    #[DataProvider('frequences')]
    public function testLeLundiEstToujoursUnJourDeComptage(int $frequence): void
    {
        self::assertContains(DayOfWeek::Mon, (new CountSchedule(frequency: $frequence))->days());
    }

    // ── Fréquence nettoyée ──────────────────────────────────────────────────

    /** @return iterable<string, array{mixed, int}> */
    public static function frequencesSaisies(): iterable
    {
        yield 'proposée' => [3, 3];
        yield 'texte numérique' => ['5', 5];
        yield 'six, non proposée' => [6, 7];
        yield 'zéro' => [0, 7];
        yield 'négative' => [-2, 7];
        yield 'au-delà de la semaine' => [12, 7];
        yield 'vide' => ['', 7];
        yield 'nulle' => [null, 7];
        yield 'texte' => ['souvent', 7];
    }

    /**
     * Repli sur 7 et non sur 1 : compter plus souvent que prévu coûte un peu de
     * temps, compter moins souvent laisse le stock filer sans témoin.
     */
    #[DataProvider('frequencesSaisies')]
    public function testUneFrequenceSaisieEstRamenéeAuxValeursProposees(mixed $brut, int $attendu): void
    {
        self::assertSame($attendu, CountSchedule::cleanFrequency($brut));
    }

    // ── Moments ─────────────────────────────────────────────────────────────

    public function testUnRythmeSansAucunMomentLesPrendLesDeux(): void
    {
        $rythme = CountSchedule::of(false, false, 7);

        self::assertTrue($rythme->morning);
        self::assertTrue($rythme->evening);
    }

    public function testUnSeulMomentCocheResteSeul(): void
    {
        $matin = CountSchedule::of(true, false, 7);
        self::assertTrue($matin->morning);
        self::assertFalse($matin->evening);

        $soir = CountSchedule::of(false, true, 7);
        self::assertFalse($soir->morning);
        self::assertTrue($soir->evening);
    }

    public function testLeMomentDecideDuComptage(): void
    {
        $soirSeul = CountSchedule::of(false, true, 7);

        self::assertTrue($soirSeul->countsAt(CountMoment::Close2200));
        self::assertFalse($soirSeul->countsAt(CountMoment::Open0800));
    }

    // ── Les deux réglages ensemble ──────────────────────────────────────────

    public function testUneLigneEstDueQuandLeJourEtLeMomentConcordent(): void
    {
        // Deux fois par semaine, le soir seulement : lundi et jeudi à 22:00.
        $rythme = CountSchedule::of(false, true, 2);

        self::assertTrue($rythme->isDue(DayOfWeek::Mon, CountMoment::Close2200));
        self::assertTrue($rythme->isDue(DayOfWeek::Thu, CountMoment::Close2200));

        // Le bon jour, mais le mauvais moment.
        self::assertFalse($rythme->isDue(DayOfWeek::Mon, CountMoment::Open0800));

        // Le bon moment, mais le mauvais jour.
        self::assertFalse($rythme->isDue(DayOfWeek::Tue, CountMoment::Close2200));
    }

    /** Le réglage par défaut ne retire rien : matin et soir, tous les jours. */
    public function testLeRythmeParDefautCompteToujours(): void
    {
        $rythme = new CountSchedule();

        foreach (DayOfWeek::all() as $jour) {
            self::assertTrue($rythme->isDue($jour, CountMoment::Open0800));
            self::assertTrue($rythme->isDue($jour, CountMoment::Close2200));
        }
    }
}
