<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ChecklistSection;
use Merisu\Inventory\Domain\ProductionGate;
use PHPUnit\Framework\TestCase;

/**
 * Ce qui ouvre et ce qui ferme l'accès à la production.
 *
 * L'erreur qui coûterait cher en cuisine : bloquer la production pour un point
 * de check-list qu'il est impossible de cocher à cette heure-là.
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
}
