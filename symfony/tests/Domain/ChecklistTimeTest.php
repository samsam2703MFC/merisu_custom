<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ChecklistItem;
use PHPUnit\Framework\TestCase;

/**
 * L'heure propre à un point de check-list.
 *
 * Le volet porte déjà la sienne ; celle-ci ne sert qu'aux points qui s'en
 * écartent. Vide veut donc dire « à l'heure du volet », et non « jamais ».
 */
final class ChecklistTimeTest extends TestCase
{
    private static function point(string $heure = ''): ChecklistItem
    {
        return new ChecklistItem(
            'p1',
            'OPENING',
            ['fr' => 'Relever les vitrines'],
            0,
            true,
            true,
            false,
            $heure,
        );
    }

    public function testSansHeureLePointSuitSonVolet(): void
    {
        $p = self::point();

        self::assertSame('', $p->executionTime);
        self::assertFalse($p->hasExecutionTime());
    }

    public function testUnPointPeutPorterSaPropreHeure(): void
    {
        $p = self::point('09:30');

        self::assertSame('09:30', $p->executionTime);
        self::assertTrue($p->hasExecutionTime());
    }

    /** Des espaces autour ne font pas une heure. */
    public function testUneHeureVideDEspacesResteVide(): void
    {
        self::assertFalse(self::point('   ')->hasExecutionTime());
    }

    /**
     * L'heure survit à une modification qui ne la touche pas — renommer un
     * point ou le rendre facultatif ne doit pas l'effacer.
     */
    public function testLHeureSurvitAUneModification(): void
    {
        $p = self::point('09:30')->with(required: false);

        self::assertSame('09:30', $p->executionTime);
        self::assertFalse($p->required);
    }
}
