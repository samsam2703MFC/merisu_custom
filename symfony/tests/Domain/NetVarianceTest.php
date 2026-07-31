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

final class NetVarianceTest extends TestCase
{
    private function net(?float $opening, ?float $closing, ?float $produced = null)
    {
        return NetVariance::compute('2026-07-31', 'p1', 'ws1', $opening, $closing, $produced);
    }

    #[Test]
    public function applique_ouverture_plus_production_moins_cloture(): void
    {
        $net = $this->net(40, 25, 60);

        self::assertSame(75.0, $net->netConsumption);
        self::assertFalse($net->partial);
    }

    #[Test]
    public function retombe_sur_ouverture_moins_cloture_sans_production_suivie(): void
    {
        $net = $this->net(40, 25);

        self::assertSame(15.0, $net->netConsumption);
        self::assertSame(0.0, $net->producedQty);
    }

    #[Test]
    public function accepte_un_ecart_nul(): void
    {
        self::assertSame(0.0, $this->net(30, 30)->netConsumption);
    }

    #[Test]
    public function remonte_un_ecart_negatif_tel_quel(): void
    {
        // Cas anormal (réapprovisionnement non déclaré) mais réel : l'admin doit le voir.
        self::assertSame(-15.0, $this->net(10, 25, 0)->netConsumption);
    }

    #[Test]
    public function marque_partiel_quand_le_comptage_du_matin_manque(): void
    {
        $net = $this->net(null, 25);

        self::assertNull($net->netConsumption);
        self::assertTrue($net->partial);
    }

    #[Test]
    public function marque_partiel_quand_le_comptage_du_soir_manque(): void
    {
        $net = $this->net(40, null);

        self::assertNull($net->netConsumption);
        self::assertTrue($net->partial);
    }

    #[Test]
    public function gere_les_decimales_sans_artefact_flottant(): void
    {
        self::assertSame(0.2, $this->net(0.3, 0.2, 0.1)->netConsumption);
    }

    #[Test]
    public function cumule_en_ignorant_les_jours_incomplets(): void
    {
        $sum = NetVariance::sum([$this->net(40, 25), $this->net(30, 10), $this->net(null, 10)]);

        self::assertSame(35.0, $sum['total']);
        self::assertSame(1, $sum['skipped']);
        self::assertTrue($sum['partial']);
    }
}
