<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Domain\TaskAccess;
use Merisu\Inventory\Domain\TaskTile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskAccessTest extends TestCase
{
    // ── La liste vide vaut « tout » ─────────────────────────────────────────

    /**
     * LA décision de ce fichier.
     *
     * Les fiches déjà en base n'ont aucune tuile enregistrée. Lire cela comme
     * « aucun droit » aurait, à la première mise à jour, renvoyé toute la
     * boutique sur un menu vide un matin à huit heures — sans qu'un vendeur
     * puisse y remédier.
     */
    #[DataProvider('toutesLesTuiles')]
    public function testSansRienDeRegleToutEstOuvert(TaskTile $tuile): void
    {
        self::assertTrue(TaskAccess::allows([], $tuile, Role::Consultant));
    }

    /** @return iterable<string, array{TaskTile}> */
    public static function toutesLesTuiles(): iterable
    {
        foreach (TaskTile::all() as $tuile) {
            yield $tuile->value => [$tuile];
        }
    }

    public function testUneRestrictionNOuvreQueCeQuiEstCoche(): void
    {
        $droits = [TaskTile::Checklist, TaskTile::Produce];

        self::assertTrue(TaskAccess::allows($droits, TaskTile::Checklist, Role::Consultant));
        self::assertTrue(TaskAccess::allows($droits, TaskTile::Produce, Role::Consultant));
        self::assertFalse(TaskAccess::allows($droits, TaskTile::Morning, Role::Consultant));
        self::assertFalse(TaskAccess::allows($droits, TaskTile::Evening, Role::Consultant));
    }

    /**
     * L'administrateur règle les droits ; il ne se les applique pas. S'étant
     * retiré une tuile, il n'aurait plus aucun moyen de la rendre à quiconque.
     */
    public function testLAdministrateurNEstJamaisRestreint(): void
    {
        foreach (TaskTile::all() as $tuile) {
            self::assertTrue(TaskAccess::allows([TaskTile::Checklist], $tuile, Role::Admin));
        }
    }

    // ── Les tuiles réellement ouvertes ──────────────────────────────────────

    public function testLesTuilesOuvertesGardentLOrdreDuMenu(): void
    {
        $ouvertes = TaskAccess::open([TaskTile::Produce, TaskTile::Morning], Role::Consultant);

        self::assertSame([TaskTile::Morning, TaskTile::Produce], $ouvertes);
    }

    public function testSansRestrictionLesQuatreTuilesSontOuvertes(): void
    {
        self::assertSame(TaskTile::all(), TaskAccess::open([], Role::Consultant));
    }

    // ── Ce que l'écran d'administration doit distinguer ─────────────────────

    /**
     * « Tout, parce que rien n'est réglé » et « tout, parce que les quatre
     * cases sont cochées » sont deux états différents : le second ne
     * survivrait pas à l'ajout d'une cinquième tuile.
     */
    public function testUneFicheSansReglageNEstPasDiteRestreinte(): void
    {
        self::assertFalse(TaskAccess::isRestricted([]));
        self::assertFalse(TaskAccess::isRestricted(TaskTile::all()));
        self::assertTrue(TaskAccess::isRestricted([TaskTile::Morning]));
    }

    // ── Lecture d'un formulaire ou de la base ───────────────────────────────

    public function testLaListeSeNettoieEtSeRangeDansLOrdreDuMenu(): void
    {
        $lue = TaskTile::cleanList(['PRODUCE', 'morning', 'PRODUCE', 'INCONNUE', null, ['CHECKLIST']]);

        self::assertSame([TaskTile::Morning, TaskTile::Produce], $lue);
    }

    public function testUneListeVideResteVide(): void
    {
        self::assertSame([], TaskTile::cleanList([]));
        self::assertSame([], TaskTile::cleanList(['', 'NIMPORTE_QUOI']));
    }

    public function testChaqueTuilePorteSonLibelleEtSaSilhouette(): void
    {
        foreach (TaskTile::all() as $tuile) {
            self::assertNotSame('', $tuile->labelKey());
            self::assertNotSame('', $tuile->icon());
        }
    }
}
