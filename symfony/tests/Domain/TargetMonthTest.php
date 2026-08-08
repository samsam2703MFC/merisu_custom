<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\TargetMonth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TargetMonthTest extends TestCase
{
    /** @return iterable<string, array{mixed, mixed}> */
    public static function moisRefuses(): iterable
    {
        yield 'avant la première année' => [2019, 6];
        yield 'mois zéro' => [2026, 0];
        yield 'treizième mois' => [2026, 13];
        yield 'année non numérique' => ['deux mille', 6];
        yield 'mois nul' => [2026, null];
    }

    /**
     * Les bornes sont celles du contrat TF Buddy (`minimum: 2020`). Les
     * respecter ici évite un aller-retour réseau pour se faire refuser une
     * saisie qu'on pouvait juger sur place.
     */
    #[DataProvider('moisRefuses')]
    public function testUnMoisHorsBornesEstRefuse(mixed $annee, mixed $mois): void
    {
        self::assertNull(TargetMonth::of($annee, $mois));
    }

    public function testUnMoisValideSeLit(): void
    {
        $mois = TargetMonth::of('2026', '8');

        self::assertNotNull($mois);
        self::assertSame(2026, $mois->year);
        self::assertSame(8, $mois->month);
        self::assertSame('2026-08', $mois->key());
    }

    public function testLeMoisSeDeduitDUneDateMetier(): void
    {
        $mois = TargetMonth::fromDate('2026-08-09');

        self::assertNotNull($mois);
        self::assertSame('2026-08', $mois->key());
        self::assertNull(TargetMonth::fromDate('pas-une-date'));
    }

    public function testLePassageDAnneeSeFaitDansLesDeuxSens(): void
    {
        $janvier = TargetMonth::of(2026, 1);
        $decembre = TargetMonth::of(2026, 12);

        self::assertNotNull($janvier);
        self::assertNotNull($decembre);

        self::assertSame('2025-12', $janvier->previous()->key());
        self::assertSame('2027-01', $decembre->next()->key());
        self::assertSame('2026-07', TargetMonth::of(2026, 8)?->previous()->key());
    }
}
