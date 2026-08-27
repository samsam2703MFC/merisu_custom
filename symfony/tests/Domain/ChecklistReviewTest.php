<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ChecklistEntry;
use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ChecklistReview;
use Merisu\Inventory\Domain\ChecklistStatus;
use PHPUnit\Framework\TestCase;

/**
 * Le suivi des tâches faites. Le cas qui compte : deux postes signent le même
 * point, et l'échec de l'un ne doit pas se cacher sous le succès de l'autre.
 */
final class ChecklistReviewTest extends TestCase
{
    private static function point(string $id, bool $required = true): ChecklistItem
    {
        return new ChecklistItem($id, 'cl-1', ['fr' => $id], 0, true, $required);
    }

    private static function signature(string $itemId, ChecklistStatus $status, string $ws = 'ws-1'): ChecklistEntry
    {
        return new ChecklistEntry('e-' . $itemId . $ws, '2026-08-27', $ws, $itemId, $status, 'c-1', '2026-08-27T08:12:00+00:00');
    }

    public function testSansSignatureToutResteEnAttente(): void
    {
        $r = ChecklistReview::build([self::point('a'), self::point('b')], []);

        self::assertSame(0, $r->done);
        self::assertSame(2, $r->total);
        self::assertFalse($r->complete());
        self::assertSame(ChecklistStatus::Pending, $r->rows[0]['status']);
    }

    public function testUnPointFaitCompte(): void
    {
        $r = ChecklistReview::build(
            [self::point('a')],
            [self::signature('a', ChecklistStatus::Done)],
        );

        self::assertSame(1, $r->done);
        self::assertTrue($r->complete());
    }

    /**
     * LE cas : échec à un poste, réussite à l'autre. L'échec gagne — un point
     * raté mérite des yeux même quand le voisin a réussi, et l'ordre inverse
     * ferait un tableau vert où les ennuis se cachent sous les succès.
     */
    public function testLEchecNeSeCachePasSousLeSuccesDunAutrePoste(): void
    {
        $r = ChecklistReview::build(
            [self::point('a')],
            [
                self::signature('a', ChecklistStatus::Done, 'ws-1'),
                self::signature('a', ChecklistStatus::Failed, 'ws-2'),
            ],
        );

        self::assertSame(ChecklistStatus::Failed, $r->rows[0]['status']);
        self::assertSame(1, $r->problems);
        self::assertSame(0, $r->done);
        // Les DEUX signatures restent lisibles : résumer n'est pas taire.
        self::assertCount(2, $r->rows[0]['entries']);
    }

    public function testUnPointPasseNeVautPasFait(): void
    {
        $r = ChecklistReview::build(
            [self::point('a')],
            [self::signature('a', ChecklistStatus::Skipped)],
        );

        self::assertSame(ChecklistStatus::Skipped, $r->rows[0]['status']);
        self::assertSame(0, $r->done);
        self::assertFalse($r->complete());
    }

    /** Un point FACULTATIF laissé en attente ne bloque pas la journée. */
    public function testUnFacultatifEnAttenteNeBloquePas(): void
    {
        $r = ChecklistReview::build(
            [self::point('a'), self::point('b', required: false)],
            [self::signature('a', ChecklistStatus::Done)],
        );

        self::assertTrue($r->complete());
    }

    /** Une liste sans point n'est jamais « complète » : il n'y a rien à finir. */
    public function testUneListeVideNEstPasComplete(): void
    {
        self::assertFalse(ChecklistReview::build([], [])->complete());
    }
}
