<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\LabelSheet;
use Merisu\Inventory\Domain\ProductionPlanRow;
use Merisu\Inventory\Domain\ProductionPlanStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LabelSheetTest extends TestCase
{
    private static function ligne(string $productId, float $qty): ProductionPlanRow
    {
        return new ProductionPlanRow(
            'plan-' . $productId,
            '2026-08-09',
            $productId,
            'ws-1',
            $qty,
            0.0,
            $qty,
            '2026-08-08T20:00:00+00:00',
            ProductionPlanStatus::Frozen,
            false,
        );
    }

    // ── Une étiquette par pièce ─────────────────────────────────────────────

    /**
     * LA règle. Trente crèmes à produire, trente pots en vitrine, trente
     * étiquettes : chacun porte ses allergènes et sa date limite.
     */
    public function testTrenteAProduireDonnentTrenteEtiquettes(): void
    {
        $planche = LabelSheet::of([self::ligne('cream', 30.0)]);

        self::assertSame(['cream' => 30], $planche->copies);
        self::assertSame(30, $planche->total);
    }

    /** @return iterable<string, array{float, int}> */
    public static function quantites(): iterable
    {
        yield 'entier' => [12.0, 12];
        yield 'une pièce' => [1.0, 1];
        yield 'demi-bac arrondi vers le haut' => [2.5, 3];
        yield "à peine plus d'une pièce" => [1.01, 2];
        yield 'rien' => [0.0, 0];
        yield 'négatif' => [-4.0, 0];
        yield 'infini' => [\INF, 0];
    }

    /**
     * L'arrondi va vers le HAUT : on ne colle pas une demi-étiquette, et il
     * vaut mieux en jeter une que d'en manquer une — le bac non étiqueté est
     * celui qui ressort du frigo sans qu'on sache de quand il date.
     */
    #[DataProvider('quantites')]
    public function testLArrondiVaVersLeHaut(float $quantite, int $attendu): void
    {
        self::assertSame($attendu, LabelSheet::copiesFor($quantite));
    }

    /** Une ligne à zéro n'a pas d'étiquette : « 0 » gaspille du papier. */
    public function testUneLigneSansRienAProduireNApparaitPas(): void
    {
        $planche = LabelSheet::of([
            self::ligne('p1', 12.0),
            self::ligne('p2', 0.0),
        ]);

        self::assertArrayNotHasKey('p2', $planche->copies);
        self::assertSame(12, $planche->total);
    }

    public function testLesLignesSAdditionnentSurLaPlanche(): void
    {
        $planche = LabelSheet::of([
            self::ligne('p1', 30.0),
            self::ligne('p2', 12.0),
            self::ligne('p3', 4.0),
        ]);

        self::assertSame(46, $planche->total);
        self::assertSame(['p1' => 30, 'p2' => 12, 'p3' => 4], $planche->copies);
        self::assertFalse($planche->isTruncated());
    }

    public function testUnPlanVideNeDonneAucuneEtiquette(): void
    {
        $planche = LabelSheet::of([]);

        self::assertTrue($planche->isEmpty());
        self::assertSame([], $planche->copies);
        self::assertFalse($planche->isTruncated());
    }

    // ── Le plafond de planche ───────────────────────────────────────────────

    /**
     * Un garde-fou contre la saisie fautive — un seuil tapé avec un zéro de
     * trop — et non une limite de métier.
     */
    public function testLaPlancheSArreteAuPlafond(): void
    {
        $planche = LabelSheet::of([self::ligne('p1', LabelSheet::MAX + 250.0)]);

        self::assertSame(LabelSheet::MAX, $planche->total);
        self::assertSame(250, $planche->dropped);
        self::assertTrue($planche->isTruncated());
    }

    /**
     * Ce qui dépasse n'est PAS perdu en silence : une planche tronquée sans le
     * dire, c'est un lot de pots qui repart sans étiquette parce que personne
     * n'a compté les feuilles sorties de l'imprimante.
     */
    public function testLeDepassementSeCompteLigneParLigne(): void
    {
        $planche = LabelSheet::of([
            self::ligne('p1', LabelSheet::MAX - 10.0),
            self::ligne('p2', 30.0),
            self::ligne('p3', 5.0),
        ]);

        self::assertSame(LabelSheet::MAX, $planche->total);
        // La deuxième ligne remplit les dix dernières places, la troisième
        // n'en obtient aucune.
        self::assertSame(10, $planche->copies['p2']);
        self::assertArrayNotHasKey('p3', $planche->copies);
        self::assertSame(25, $planche->dropped);
    }

    public function testLeCompteExactDuPlafondNeSeDeclarePasTronque(): void
    {
        $planche = LabelSheet::of([self::ligne('p1', (float) LabelSheet::MAX)]);

        self::assertSame(LabelSheet::MAX, $planche->total);
        self::assertFalse($planche->isTruncated());
    }
}
