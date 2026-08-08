<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\ParMatrixEntry;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\Production;
use Merisu\Inventory\Domain\ProductNature;
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

    /** Une matière première : elle s'achète, elle ne se fabrique pas. */
    private function matiere(string $id): Product
    {
        return $this->product($id)->with(nature: ProductNature::Raw);
    }

    #[Test]
    public function une_matiere_premiere_n_entre_pas_au_plan(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1'), $this->matiere('mascarpone')],
            ['p1' => 10.0, 'mascarpone' => 2.0],
            [$this->par('p1', DayOfWeek::Sat, 25), $this->par('mascarpone', DayOfWeek::Sat, 40)],
            'ws1',
        );

        self::assertCount(1, $plan->lines);
        self::assertSame('p1', $plan->lines[0]->productId);
    }

    /**
     * Aucune matière n'a de seuil dans la matrice. Sans le filtre, chacune en
     * ajoutait un « seuil manquant » qui noyait les avertissements réels.
     */
    #[Test]
    public function une_matiere_premiere_n_avertit_pas_d_un_seuil_manquant(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1'), $this->matiere('cacao')],
            ['p1' => 0.0],
            [$this->par('p1', DayOfWeek::Sat, 5)],
            'ws1',
        );

        self::assertSame([], $plan->warningsMissingThreshold);
    }

    /** Un plan de matières seules est vide, non erroné : il n'y a rien à faire. */
    #[Test]
    public function un_plan_de_matieres_seules_est_vide(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->matiere('m1'), $this->matiere('m2')],
            [],
            [],
            'ws1',
        );

        self::assertSame([], $plan->lines);
        self::assertSame('2026-08-01', $plan->forDate);
    }

    /**
     * Une fiche muette sur sa nature — base installée avant la distinction —
     * reste fabriquée. La retirer du plan la ferait manquer en rayon.
     */
    #[Test]
    public function un_produit_sans_nature_declaree_reste_au_plan(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1')],
            ['p1' => 0.0],
            [$this->par('p1', DayOfWeek::Sat, 6)],
            'ws1',
        );

        self::assertCount(1, $plan->lines);
        self::assertSame(6.0, $plan->lines[0]->qtyToProduce);
    }

    // ── Le minimum calculé pilote le plan ───────────────────────────────────

    /**
     * Une composition se fabrique : ce qu'il faut en avoir demain, c'est ce qui
     * s'est écoulé, pas un chiffre posé il y a six mois.
     */
    #[Test]
    public function le_minimum_calcule_prime_sur_le_seuil_saisi(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1')],
            ['p1' => 0.0],
            [$this->par('p1', DayOfWeek::Sat, 100)],   // seuil saisi : 100
            'ws1',
            ['p1' => 24.0],                            // minimum calculé : 24
        );

        self::assertSame(24.0, $plan->lines[0]->requiredPieces);
        self::assertSame(24.0, $plan->lines[0]->qtyToProduce);
        self::assertTrue($plan->lines[0]->fromHistory);
    }

    /** Il PRIME, il n'additionne pas : les cumuler doublerait la production. */
    #[Test]
    public function le_minimum_calcule_ne_s_ajoute_pas_au_seuil(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1')],
            ['p1' => 0.0],
            [$this->par('p1', DayOfWeek::Sat, 30)],
            'ws1',
            ['p1' => 20.0],
        );

        self::assertSame(20.0, $plan->lines[0]->qtyToProduce);
    }

    /**
     * Sans minimum calculé — fiche trop jeune, ou relevés trop rares pour
     * qu'une moyenne veuille dire quelque chose — le seuil saisi reprend la
     * main. C'est le filet, et il doit tenir.
     */
    #[Test]
    public function sans_minimum_calcule_le_seuil_saisi_reprend_la_main(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1'), $this->product('p2')],
            ['p1' => 0.0, 'p2' => 0.0],
            [$this->par('p1', DayOfWeek::Sat, 15), $this->par('p2', DayOfWeek::Sat, 40)],
            'ws1',
            ['p2' => 12.0],
        );

        self::assertSame(15.0, $plan->lines[0]->qtyToProduce);
        self::assertFalse($plan->lines[0]->fromHistory);
        self::assertSame(12.0, $plan->lines[1]->qtyToProduce);
        self::assertTrue($plan->lines[1]->fromHistory);
    }

    /** Un minimum calculé rend l'absence de seuil sans conséquence. */
    #[Test]
    public function un_minimum_calcule_leve_l_avertissement_de_seuil_manquant(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1')],
            ['p1' => 0.0],
            [],                                        // aucun seuil saisi
            'ws1',
            ['p1' => 18.0],
        );

        self::assertSame([], $plan->warningsMissingThreshold);
        self::assertFalse($plan->lines[0]->missingThreshold);
        self::assertSame(18.0, $plan->lines[0]->qtyToProduce);
    }

    /** Le stock de clôture se retranche du minimum calculé comme du seuil. */
    #[Test]
    public function le_stock_de_cloture_se_retranche_du_minimum_calcule(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1', wasteFactor: 0.1)],
            ['p1' => 5.0],
            [],
            'ws1',
            ['p1' => 25.0],
        );

        // (25 − 5) × 1,1 = 22
        self::assertSame(22.0, $plan->lines[0]->qtyToProduce);
    }

    /** Un minimum calculé nul veut dire « rien à produire », pas « pas de seuil ». */
    #[Test]
    public function un_minimum_calcule_nul_ne_passe_pas_pour_un_seuil_absent(): void
    {
        $plan = Production::plan(
            '2026-07-31',
            [$this->product('p1')],
            ['p1' => 0.0],
            [$this->par('p1', DayOfWeek::Sat, 50)],
            'ws1',
            ['p1' => 0.0],
        );

        self::assertSame(0.0, $plan->lines[0]->qtyToProduce);
        self::assertFalse($plan->lines[0]->missingThreshold);
        self::assertTrue($plan->lines[0]->fromHistory);
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
