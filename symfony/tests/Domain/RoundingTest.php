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

final class RoundingTest extends TestCase
{
    #[Test]
    public function arrondit_au_multiple_superieur(): void
    {
        self::assertSame(19.0, Rounding::apply(18.1, 1, RoundingMode::Ceil));
        self::assertSame(18.0, Rounding::apply(18.0, 1, RoundingMode::Ceil));
    }

    #[Test]
    public function arrondit_au_multiple_inferieur_et_au_plus_proche(): void
    {
        self::assertSame(18.0, Rounding::apply(18.9, 1, RoundingMode::Floor));
        self::assertSame(18.0, Rounding::apply(18.4, 1, RoundingMode::Nearest));
        self::assertSame(19.0, Rounding::apply(18.5, 1, RoundingMode::Nearest));
    }

    #[Test]
    public function respecte_un_pas_de_lot(): void
    {
        self::assertSame(18.0, Rounding::apply(13, 6, RoundingMode::Ceil));
        self::assertSame(12.0, Rounding::apply(12, 6, RoundingMode::Ceil));
        self::assertSame(12.0, Rounding::apply(13, 6, RoundingMode::Nearest));
    }

    #[Test]
    public function respecte_un_pas_decimal(): void
    {
        self::assertSame(0.5, Rounding::apply(0.26, 0.5, RoundingMode::Ceil));
        self::assertSame(1.2, Rounding::apply(1.2, 0.1, RoundingMode::Ceil));
    }

    #[Test]
    public function evite_les_artefacts_de_virgule_flottante(): void
    {
        // 18.9 / 0.1 vaut 188.999… en flottant : sans nettoyage, on obtiendrait 19.0.
        self::assertSame(18.9, Rounding::apply(18.9, 0.1, RoundingMode::Ceil));
    }

    #[Test]
    public function laisse_la_valeur_intacte_en_mode_none(): void
    {
        self::assertSame(164.45, Rounding::apply(164.45, 1, RoundingMode::None));
    }

    #[Test]
    public function ignore_un_pas_invalide(): void
    {
        self::assertSame(18.3, Rounding::apply(18.3, 0, RoundingMode::Ceil));
        self::assertSame(18.3, Rounding::apply(18.3, -2, RoundingMode::Ceil));
    }

    #[Test]
    public function gere_zero(): void
    {
        self::assertSame(0.0, Rounding::apply(0, 6, RoundingMode::Ceil));
    }
}
