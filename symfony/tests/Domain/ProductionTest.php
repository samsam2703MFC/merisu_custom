<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\ParMatrixEntry;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\Production;
use Merisu\Inventory\Domain\RoundingMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionTest extends TestCase
{
    private function product(
        string $id = 'p1',
        float $wasteFactor = 0.0,
        float $roundingStep = 1.0,
        RoundingMode $mode = RoundingMode::Ceil,
        string $unit = 'pcs',
    ): Product {
        return new Product($id, strtoupper($id), ['fr' => 'Slot'], $unit, true, $wasteFactor, $roundingStep, $mode, null, 1);
    }

    private function par(string $productId, DayOfWeek $day, float $qty, ?string $workstationId = null): ParMatrixEntry
    {
        return new ParMatrixEntry(uniqid(), $productId, $day, $qty, $workstationId);
    }

    #[Test]
    public function produit_la_difference_entre_le_seuil_et_le_stock_de_cloture(): void
    {
        $line = Production::line($this->product(), closingStock: 12, requiredPieces: 30);

        self::assertSame(18.0, $line->rawQtyToProduce);
        self::assertSame(18.0, $line->qtyToProduce);
        self::assertFalse($line->missingThreshold);
    }

    #[Test]
    public function renvoie_zero_quand_le_stock_depasse_le_requis(): void
    {
        $line = Production::line($this->product(), closingStock: 50, requiredPieces: 30);

        self::assertSame(0.0, $line->qtyToProduce);
    }

    #[Test]
    public function renvoie_zero_quand_le_stock_egale_exactement_le_requis(): void
    {
        self::assertSame(0.0, Production::line($this->product(), 30, 30)->qtyToProduce);
    }

    #[Test]
    public function produit_tout_le_requis_quand_le_stock_est_nul(): void
    {
        self::assertSame(30.0, Production::line($this->product(), 0, 30)->qtyToProduce);
    }

    #[Test]
    public function applique_le_facteur_de_perte_puis_l_arrondi(): void
    {
        // 30 − 12 = 18 ; 18 × 1.05 = 18.9 ; arrondi supérieur au pas 1 → 19
        $line = Production::line($this->product(wasteFactor: 0.05), 12, 30);

        self::assertSame(18.0, $line->rawQtyToProduce);
        self::assertSame(19.0, $line->qtyToProduce);
    }

    #[Test]
    public function n_applique_pas_le_facteur_de_perte_a_un_besoin_nul(): void
    {
        self::assertSame(0.0, Production::line($this->product(wasteFactor: 0.5), 100, 30)->qtyToProduce);
    }

    #[Test]
    public function respecte_un_arrondi_par_lot(): void
    {
        // 20 − 3 = 17 → multiple de 6 supérieur → 18
        self::assertSame(18.0, Production::line($this->product(roundingStep: 6), 3, 20)->qtyToProduce);
    }

    #[Test]
    public function conserve_les_decimales_quand_l_arrondi_est_desactive(): void
    {
        // (250 − 100.5) × 1.1 = 164.45
        $line = Production::line(
            $this->product(wasteFactor: 0.1, mode: RoundingMode::None, unit: 'g'),
            100.5,
            250,
        );

        self::assertEqualsWithDelta(164.45, $line->qtyToProduce, 0.000001);
    }

    #[Test]
    public function traite_un_seuil_absent_comme_zero_et_leve_un_avertissement(): void
    {
        $line = Production::line($this->product(), closingStock: 5, requiredPieces: null);

        self::assertSame(0.0, $line->qtyToProduce);
        self::assertTrue($line->missingThreshold);
    }

    #[Test]
    public function traite_un_stock_de_cloture_absent_comme_zero_en_le_signalant(): void
    {
        $line = Production::line($this->product(), closingStock: null, requiredPieces: 40);

        self::assertSame(40.0, $line->qtyToProduce);
        self::assertTrue($line->missingClosingStock);
    }

    #[Test]
    public function ignore_un_facteur_de_perte_negatif(): void
    {
        self::assertSame(10.0, Production::line($this->product(wasteFactor: -0.5), 0, 10)->qtyToProduce);
    }

    #[Test]
    public function le_plan_vise_le_lendemain_et_son_jour_de_semaine(): void
    {
        // 2026-07-31 est un vendredi → demain samedi.
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1'), $this->product('p2', wasteFactor: 0.1)],
            ['p1' => 10.0, 'p2' => 0.0],
            [$this->par('p1', DayOfWeek::Sat, 25), $this->par('p2', DayOfWeek::Sat, 10)],
            'ws1',
        );

        self::assertSame('2026-08-01', $plan->forDate);
        self::assertSame(DayOfWeek::Sat, $plan->forDayOfWeek);
        self::assertSame(15.0, $plan->lines[0]->qtyToProduce);
        self::assertSame(11.0, $plan->lines[1]->qtyToProduce); // (10 − 0) × 1.1
    }

    #[Test]
    public function gere_le_passage_dimanche_vers_lundi(): void
    {
        // 2026-08-02 est un dimanche : c'est le seuil du LUNDI qui doit s'appliquer.
        $plan = Production::plan(
            '2026-08-02',
            [$this->product('p1')],
            ['p1' => 4.0],
            [$this->par('p1', DayOfWeek::Mon, 20), $this->par('p1', DayOfWeek::Sun, 99)],
            'ws1',
        );

        self::assertSame(DayOfWeek::Mon, $plan->forDayOfWeek);
        self::assertSame('2026-08-03', $plan->forDate);
        self::assertSame(16.0, $plan->lines[0]->qtyToProduce);
    }

    #[Test]
    public function gere_le_passage_de_mois_et_d_annee(): void
    {
        // 2026-12-31 est un jeudi → vendredi 2027-01-01.
        $plan = Production::plan(
            '2026-12-31',
            [$this->product('p1')],
            ['p1' => 0.0],
            [$this->par('p1', DayOfWeek::Fri, 7)],
            'ws1',
        );

        self::assertSame('2027-01-01', $plan->forDate);
        self::assertSame(DayOfWeek::Fri, $plan->forDayOfWeek);
        self::assertSame(7.0, $plan->lines[0]->qtyToProduce);
    }

    #[Test]
    public function remonte_les_produits_sans_seuil_defini(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1'), $this->product('p2')],
            ['p1' => 0.0, 'p2' => 0.0],
            [$this->par('p1', DayOfWeek::Sat, 5)],
            'ws1',
        );

        self::assertSame(['p2'], $plan->warningsMissingThreshold);
        self::assertSame(0.0, $plan->lines[1]->qtyToProduce);
    }

    #[Test]
    public function utilise_le_seuil_global_faute_de_seuil_poste(): void
    {
        $matrix = [$this->par('p1', DayOfWeek::Mon, 20), $this->par('p1', DayOfWeek::Mon, 35, 'ws2')];

        self::assertSame(20.0, Production::resolveRequiredPieces($matrix, 'p1', DayOfWeek::Mon, 'ws1'));
    }

    #[Test]
    public function privilegie_le_seuil_du_poste_quand_il_existe(): void
    {
        $matrix = [$this->par('p1', DayOfWeek::Mon, 20), $this->par('p1', DayOfWeek::Mon, 35, 'ws2')];

        self::assertSame(35.0, Production::resolveRequiredPieces($matrix, 'p1', DayOfWeek::Mon, 'ws2'));
    }

    #[Test]
    public function distingue_seuil_absent_et_seuil_defini_a_zero(): void
    {
        self::assertNull(Production::resolveRequiredPieces([], 'p1', DayOfWeek::Tue, 'ws1'));
        self::assertSame(
            0.0,
            Production::resolveRequiredPieces([$this->par('p1', DayOfWeek::Tue, 0)], 'p1', DayOfWeek::Tue, 'ws1'),
        );
    }
}
