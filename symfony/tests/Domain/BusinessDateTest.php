<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\Delta;
use Merisu\Inventory\Domain\EveningValidation;
use Merisu\Inventory\Domain\NetVariance;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Domain\Rounding;
use Merisu\Inventory\Domain\RoundingMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BusinessDateTest extends TestCase
{
    #[Test]
    public function demarre_la_semaine_au_lundi(): void
    {
        self::assertSame(DayOfWeek::Mon, BusinessDate::dayOfWeek('2026-08-03'));
        self::assertSame(DayOfWeek::Sun, BusinessDate::dayOfWeek('2026-08-02'));
        self::assertSame(DayOfWeek::Fri, BusinessDate::dayOfWeek('2026-07-31'));
    }

    #[Test]
    public function gere_les_passages_de_semaine_mois_et_annee(): void
    {
        self::assertSame('2026-08-03', BusinessDate::next('2026-08-02'));
        self::assertSame('2026-09-01', BusinessDate::next('2026-08-31'));
        self::assertSame('2027-01-01', BusinessDate::next('2026-12-31'));
        self::assertSame('2026-12-31', BusinessDate::previous('2027-01-01'));
    }

    #[Test]
    public function gere_une_annee_bissextile(): void
    {
        self::assertSame('2028-02-29', BusinessDate::next('2028-02-28'));
        self::assertSame('2028-03-01', BusinessDate::next('2028-02-29'));
        self::assertSame('2026-03-01', BusinessDate::next('2026-02-28'));
    }

    #[Test]
    public function rejette_les_dates_inexistantes_ou_mal_formees(): void
    {
        self::assertFalse(BusinessDate::isValid('2026-13-01'));
        self::assertFalse(BusinessDate::isValid('2026-02-30'));
        self::assertFalse(BusinessDate::isValid('31/07/2026'));
        self::assertTrue(BusinessDate::isValid('2026-07-31'));
    }

    #[Test]
    public function leve_une_erreur_explicite_sur_une_date_invalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Date métier invalide/');

        BusinessDate::next('pas-une-date');
    }

    #[Test]
    public function produit_une_plage_inclusive(): void
    {
        self::assertSame(
            ['2026-07-30', '2026-07-31', '2026-08-01'],
            BusinessDate::range('2026-07-30', '2026-08-01'),
        );
        self::assertSame([], BusinessDate::range('2026-08-01', '2026-07-30'));
    }

    #[Test]
    public function utilise_le_fuseau_configure_et_non_celui_du_serveur(): void
    {
        // 23:30 UTC le 31 juillet, c'est déjà le 1er août à Varsovie (UTC+2 en été).
        $instant = new \DateTimeImmutable('2026-07-31T23:30:00Z');

        self::assertSame('2026-08-01', BusinessDate::today('Europe/Warsaw', $instant));
        self::assertSame('2026-07-31', BusinessDate::today('UTC', $instant));
    }

    #[Test]
    public function retombe_sur_utc_si_le_fuseau_est_inconnu(): void
    {
        $instant = new \DateTimeImmutable('2026-07-31T10:00:00Z');

        self::assertSame('2026-07-31', BusinessDate::today('Zone/Inexistante', $instant));
    }
}
