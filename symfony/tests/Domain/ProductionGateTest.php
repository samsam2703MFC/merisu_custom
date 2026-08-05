<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ChecklistSection;
use Merisu\Inventory\Domain\ProductionGate;
use Merisu\Inventory\Domain\ProductionStop;
use PHPUnit\Framework\TestCase;

/**
 * Ce qui ouvre et ce qui ferme l'accès à la production.
 *
 * Deux erreurs coûteraient cher en cuisine : laisser produire alors qu'un
 * arrêt court (four en panne, contrôle sanitaire), et bloquer la production
 * pour un point de check-list qu'il est impossible de cocher à cette heure-là.
 */
final class ProductionGateTest extends TestCase
{
    private function item(
        string $id,
        ChecklistSection $section,
        bool $required = true,
    ): ChecklistItem {
        return new ChecklistItem($id, $section, ['fr' => $id], 1, true, $required);
    }

    private function stop(?string $liftedAt = null): ProductionStop
    {
        return new ProductionStop(
            'arret-1',
            'poste-1',
            'Four en panne',
            'consultant-1',
            '2026-08-05 09:12:00',
            $liftedAt === null ? null : 'consultant-2',
            $liftedAt,
        );
    }

    // ── Verrou de check-list ────────────────────────────────────────────────

    public function testUnPointObligatoireNonCocheBloque(): void
    {
        $items = [$this->item('ouverture-1', ChecklistSection::Opening)];

        $blocking = ProductionGate::blockingItems($items, []);

        self::assertCount(1, $blocking);
        self::assertSame('ouverture-1', $blocking[0]->id);
    }

    public function testUnPointObligatoireCocheNeBloquePlus(): void
    {
        $items = [$this->item('ouverture-1', ChecklistSection::Opening)];

        self::assertSame([], ProductionGate::blockingItems($items, ['ouverture-1' => true]));
    }

    public function testUnPointFacultatifNeBloqueJamais(): void
    {
        $items = [$this->item('ouverture-2', ChecklistSection::Opening, required: false)];

        self::assertSame([], ProductionGate::blockingItems($items, []));
    }

    /**
     * Le cas qui justifie l'exclusion : les points de fermeture se cochent le
     * soir, APRÈS la production. Les exiger avant fermerait la porte pour de
     * bon, et personne n'en aurait la clé.
     */
    public function testLeVoletFermetureNeBloqueJamaisLaProduction(): void
    {
        $items = [$this->item('fermeture-1', ChecklistSection::Closing)];

        self::assertSame([], ProductionGate::blockingItems($items, []));
    }

    public function testLeControleQualiteBloqueCommeLOuverture(): void
    {
        $items = [$this->item('qualite-1', ChecklistSection::Quality)];

        self::assertCount(1, ProductionGate::blockingItems($items, []));
    }

    public function testUnPointDecocheBloqueAutantQuUnPointJamaisTouche(): void
    {
        $items = [
            $this->item('jamais-touche', ChecklistSection::Opening),
            $this->item('decoche', ChecklistSection::Opening),
        ];

        $blocking = ProductionGate::blockingItems($items, ['decoche' => false]);

        self::assertCount(2, $blocking);
    }

    public function testLOrdreDesPointsEstConserve(): void
    {
        $items = [
            $this->item('a', ChecklistSection::Opening),
            $this->item('b', ChecklistSection::Quality),
            $this->item('c', ChecklistSection::Opening),
        ];

        $ids = array_map(static fn (ChecklistItem $i): string => $i->id, ProductionGate::blockingItems($items, []));

        self::assertSame(['a', 'b', 'c'], $ids);
    }

    // ── Décision d'ensemble ─────────────────────────────────────────────────

    public function testProduitQuandRienNeSyOppose(): void
    {
        self::assertTrue(ProductionGate::allows(null, []));
    }

    public function testUnArretEnCoursInterditDeProduire(): void
    {
        self::assertFalse(ProductionGate::allows($this->stop(), []));
    }

    /** Un arrêt levé appartient à l'historique : il ne bloque plus rien. */
    public function testUnArretLeveNInterditPlusRien(): void
    {
        $leve = $this->stop('2026-08-05 11:30:00');

        self::assertFalse($leve->isActive());
        self::assertTrue(ProductionGate::allows($leve, []));
    }

    public function testUnPointObligatoireManquantInterditDeProduire(): void
    {
        $blocking = [$this->item('ouverture-1', ChecklistSection::Opening)];

        self::assertFalse(ProductionGate::allows(null, $blocking));
    }

    /**
     * Les deux verrous s'additionnent : lever l'arrêt sans finir la check-list
     * n'ouvre pas davantage que finir la check-list sous arrêt.
     */
    public function testLesDeuxVerrousSAdditionnent(): void
    {
        $blocking = [$this->item('ouverture-1', ChecklistSection::Opening)];

        self::assertFalse(ProductionGate::allows($this->stop(), $blocking));
        self::assertFalse(ProductionGate::allows($this->stop('2026-08-05 11:30:00'), $blocking));
        self::assertFalse(ProductionGate::allows($this->stop(), []));
        self::assertTrue(ProductionGate::allows($this->stop('2026-08-05 11:30:00'), []));
    }
}
