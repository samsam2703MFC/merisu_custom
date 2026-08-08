<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ProductionEntry;
use Merisu\Inventory\Domain\ProductionPlanRow;
use Merisu\Inventory\Domain\ProductionPlanStatus;
use Merisu\Inventory\Domain\ProductionProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionProgressTest extends TestCase
{
    private static function ligne(string $productId, float $qty, bool $sansSeuil = false): ProductionPlanRow
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
            $sansSeuil,
        );
    }

    /** @param list<string> $productIds */
    private static function signees(array $productIds): array
    {
        $entries = [];

        foreach ($productIds as $id) {
            $entries[$id] = new ProductionEntry('2026-08-09', 'ws-1', $id, 12.0, 'consultant1', '2026-08-09T11:00:00+00:00');
        }

        return $entries;
    }

    // ── Ce qui entre au dénominateur ────────────────────────────────────────

    /**
     * Un plan de vingt lignes dont quinze sont à zéro — le stock du soir
     * suffisait — n'est pas « 5/20 fait ». Afficher 25 % là où l'atelier a
     * tout terminé décourage pour rien.
     */
    public function testUneLigneSansRienAProduireNeCompteNulPart(): void
    {
        $avancement = ProductionProgress::of([
            self::ligne('p1', 12.0),
            self::ligne('p2', 0.0),
            self::ligne('p3', 0.0),
        ], self::signees(['p1']));

        self::assertSame(1, $avancement->total);
        self::assertSame(1, $avancement->done);
        self::assertSame(100, $avancement->percent);
        self::assertTrue($avancement->isComplete());
    }

    /**
     * Une ligne sans seuil compte : sa quantité est peut-être fausse, mais
     * elle est affichée, et quelqu'un devra la traiter.
     */
    public function testUneLigneSansSeuilCompteMalgreSaQuantiteNulle(): void
    {
        $avancement = ProductionProgress::of([self::ligne('p1', 0.0, sansSeuil: true)], []);

        self::assertSame(1, $avancement->total);
        self::assertSame(0, $avancement->done);
        self::assertFalse($avancement->isComplete());
    }

    /**
     * L'écran pose la MÊME question, ligne par ligne, pour décider s'il
     * affiche une case à cocher. Deux définitions du « à faire » auraient fini
     * par diverger, et la barre n'aurait plus décrit les cases affichées.
     */
    public function testLEcranEtLaBarrePosentLaMemeQuestion(): void
    {
        self::assertTrue(ProductionProgress::isActionable(self::ligne('p1', 12.0)));
        self::assertTrue(ProductionProgress::isActionable(self::ligne('p1', 0.0, sansSeuil: true)));
        self::assertFalse(ProductionProgress::isActionable(self::ligne('p1', 0.0)));
    }

    // ── Le décompte ─────────────────────────────────────────────────────────

    /** @return iterable<string, array{int, int, int}> */
    public static function proportions(): iterable
    {
        yield 'rien de fait' => [0, 4, 0];
        yield 'un quart' => [1, 4, 25];
        yield 'la moitié' => [2, 4, 50];
        yield 'tout' => [4, 4, 100];
        yield 'arrondi au plus proche' => [1, 3, 33];
        yield 'arrondi vers le haut' => [2, 3, 67];
    }

    #[DataProvider('proportions')]
    public function testLaProportionSeLitEnPourcentage(int $faites, int $total, int $attendu): void
    {
        $lignes = [];
        $ids = [];

        foreach (range(1, $total) as $n) {
            $lignes[] = self::ligne('p' . $n, 6.0);

            if ($n <= $faites) {
                $ids[] = 'p' . $n;
            }
        }

        $avancement = ProductionProgress::of($lignes, self::signees($ids));

        self::assertSame($attendu, $avancement->percent);
        self::assertSame($total - $faites, $avancement->left());
    }

    /**
     * Zéro sur zéro n'est pas 0 % : il n'y a rien à faire, donc tout est fait.
     * Le contraire aurait affiché une barre vide sur un plan vide.
     */
    public function testUnPlanSansRienAFaireEstComplet(): void
    {
        $avancement = ProductionProgress::of([], []);

        self::assertSame(0, $avancement->total);
        self::assertSame(100, $avancement->percent);
        self::assertTrue($avancement->isComplete());
        self::assertSame(0, $avancement->left());
    }

    /**
     * La signature d'une ligne retirée du plan ne compte pas : une
     * revalidation du comptage du soir peut faire disparaître une ligne, et
     * l'atelier afficherait sinon « 3 sur 2 ».
     */
    public function testUneSignatureHorsPlanNeCompteNulPart(): void
    {
        $avancement = ProductionProgress::of(
            [self::ligne('p1', 12.0)],
            self::signees(['p1', 'p2', 'p3']),
        );

        self::assertSame(1, $avancement->total);
        self::assertSame(1, $avancement->done);
        self::assertSame(0, $avancement->left());
    }
}
