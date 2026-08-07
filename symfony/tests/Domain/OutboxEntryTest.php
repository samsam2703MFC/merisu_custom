<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\OutboxEntry;
use Merisu\Inventory\Domain\SyncKind;
use Merisu\Inventory\Domain\SyncStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboxEntryTest extends TestCase
{
    private static function entree(
        SyncStatus $statut = SyncStatus::Pending,
        ?string $prochaine = null,
        int $tentatives = 0,
    ): OutboxEntry {
        return new OutboxEntry(1, SyncKind::ProductInventory, ['qty' => 3], $statut, $tentatives,
            null, '2026-08-07T10:00:00+00:00', null, $prochaine);
    }

    // ── Recul entre deux tentatives ─────────────────────────────────────────

    /** @return iterable<string, array{int, int}> */
    public static function reculs(): iterable
    {
        yield 'première' => [1, 60];
        yield 'deuxième' => [2, 120];
        yield 'troisième' => [3, 240];
        yield 'quatrième' => [4, 480];
        yield 'cinquième' => [5, 960];
        yield 'sixième' => [6, 1920];
        yield 'septième, plafonnée' => [7, 3600];
        yield 'huitième, plafonnée' => [8, 3600];
    }

    #[DataProvider('reculs')]
    public function testLeReculDoubleJusquAuPlafond(int $tentatives, int $attendu): void
    {
        self::assertSame($attendu, OutboxEntry::backoffSeconds($tentatives));
    }

    /**
     * Le plafond compte autant que la croissance : sans lui, la sixième
     * tentative attendrait une demi-journée, et une panne de dix minutes
     * coûterait une journée de retard.
     */
    public function testLeReculNeDepasseJamaisUneHeure(): void
    {
        foreach (range(1, 40) as $n) {
            self::assertLessThanOrEqual(3600, OutboxEntry::backoffSeconds($n));
        }
    }

    /** Un compteur aberrant ne doit pas produire un recul négatif ou nul. */
    public function testUnCompteurAberrantDonneLeReculMinimal(): void
    {
        self::assertSame(60, OutboxEntry::backoffSeconds(0));
        self::assertSame(60, OutboxEntry::backoffSeconds(-5));
    }

    /** Huit tentatives couvrent plus de deux heures — un redémarrage d'hôte. */
    public function testLesTentativesCouvrentPlusDeDeuxHeures(): void
    {
        $total = array_sum(array_map(OutboxEntry::backoffSeconds(...), range(1, OutboxEntry::MAX_ATTEMPTS)));

        self::assertGreaterThan(7200, $total);
    }

    // ── Éligibilité à l'envoi ───────────────────────────────────────────────

    public function testUneEntreeNeuveEstDueImmediatement(): void
    {
        self::assertTrue(self::entree()->isDue('2026-08-07T10:00:00+00:00'));
    }

    public function testUneEntreeEnAttenteDeReculNEstPasDue(): void
    {
        $e = self::entree(prochaine: '2026-08-07T10:05:00+00:00');

        self::assertFalse($e->isDue('2026-08-07T10:04:59+00:00'));
        self::assertTrue($e->isDue('2026-08-07T10:05:00+00:00'));
        self::assertTrue($e->isDue('2026-08-07T10:06:00+00:00'));
    }

    /** Une entrée déjà réglée ne repart jamais, même à l'heure dite. */
    public function testUneEntreeRegleeNEstJamaisDue(): void
    {
        self::assertFalse(self::entree(SyncStatus::Sent)->isDue('2027-01-01T00:00:00+00:00'));
        self::assertFalse(self::entree(SyncStatus::Failed)->isDue('2027-01-01T00:00:00+00:00'));
    }

    public function testLEpuisementSeMesureAuNombreDeTentatives(): void
    {
        self::assertFalse(self::entree(tentatives: OutboxEntry::MAX_ATTEMPTS - 1)->isExhausted());
        self::assertTrue(self::entree(tentatives: OutboxEntry::MAX_ATTEMPTS)->isExhausted());
        self::assertTrue(self::entree(tentatives: OutboxEntry::MAX_ATTEMPTS + 3)->isExhausted());
    }

    // ── États ───────────────────────────────────────────────────────────────

    public function testSeulLAttenteNEstPasReglee(): void
    {
        self::assertFalse(SyncStatus::Pending->isSettled());
        self::assertTrue(SyncStatus::Sent->isSettled());
        self::assertTrue(SyncStatus::Failed->isSettled());
    }

    public function testLaNatureSeRelitDepuisLaBase(): void
    {
        self::assertSame(SyncKind::ProductInventory, SyncKind::fromLoose('PRODUCT_INVENTORY'));
        self::assertSame(SyncKind::MaterialStocktaking, SyncKind::fromLoose('material_stocktaking'));
        self::assertNull(SyncKind::fromLoose('AUTRE'));
        self::assertNull(SyncKind::fromLoose(null));
        self::assertNull(SyncKind::fromLoose(['PRODUCT_INVENTORY']));
    }
}
