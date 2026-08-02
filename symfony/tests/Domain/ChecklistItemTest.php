<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ChecklistSection;
use Merisu\Inventory\Domain\Locale;
use PHPUnit\Framework\TestCase;

/**
 * Le repli des libellés décide de ce que lit le vendeur au poste. Une cuisine
 * polonaise dont l'administrateur n'a rempli que le français doit voir le
 * français — jamais une ligne vide, qui ferait passer un contrôle à la trappe.
 */
final class ChecklistItemTest extends TestCase
{
    private function item(array $labels): ChecklistItem
    {
        return new ChecklistItem('ouverture-1', ChecklistSection::Opening, $labels, 1, true);
    }

    public function testRendLeLibelleDeLaLangueDemandee(): void
    {
        $item = $this->item(['fr' => 'Vitrine essuyée', 'pl' => 'Witryna przetarta']);

        self::assertSame('Witryna przetarta', $item->text(Locale::Pl));
        self::assertSame('Vitrine essuyée', $item->text(Locale::Fr));
    }

    public function testReplieSurLaLangueParDefautQuandLaTraductionManque(): void
    {
        $item = $this->item(['fr' => 'Vitrine essuyée']);

        self::assertSame('Vitrine essuyée', $item->text(Locale::Pl, Locale::Fr));
    }

    public function testReplieSurNimporteQuelleLangueRenseignee(): void
    {
        // Ni l'italien demandé, ni le français par défaut : reste l'espagnol.
        $item = $this->item(['es' => 'Vitrina limpia']);

        self::assertSame('Vitrina limpia', $item->text(Locale::It, Locale::Fr));
    }

    public function testIgnoreLesLibellesVides(): void
    {
        $item = $this->item(['pl' => '   ', 'fr' => 'Vitrine essuyée']);

        self::assertSame('Vitrine essuyée', $item->text(Locale::Pl, Locale::Fr));
    }

    public function testRendLIdentifiantFauteDeToutLibelle(): void
    {
        // Signale à l'administrateur qu'un libellé manque, plutôt qu'une ligne
        // vide que le vendeur cocherait sans savoir ce qu'il atteste.
        self::assertSame('ouverture-1', $this->item([])->text(Locale::Fr));
    }

    public function testChaqueVoletAUneIconeEtUnLibelle(): void
    {
        foreach (ChecklistSection::all() as $section) {
            self::assertNotSame('', $section->icon());
            self::assertStringStartsWith('checklist.sections.', $section->labelKey());
        }
    }

    public function testLesTroisVoletsSontDansLOrdreDeLaJournee(): void
    {
        self::assertSame(
            [ChecklistSection::Opening, ChecklistSection::Closing, ChecklistSection::Quality],
            ChecklistSection::all(),
        );
    }

    public function testLaSectionSeLitSansEgardALaCasse(): void
    {
        self::assertSame(ChecklistSection::Quality, ChecklistSection::tryFromLoose(' quality '));
        self::assertNull(ChecklistSection::tryFromLoose('INCONNU'));
        self::assertNull(ChecklistSection::tryFromLoose(null));
    }

    public function testWithNeTouchePasALOriginal(): void
    {
        $item = $this->item(['fr' => 'Vitrine essuyée']);
        $modifie = $item->with(active: false, required: false);

        self::assertTrue($item->active);
        self::assertTrue($item->required);
        self::assertFalse($modifie->active);
        self::assertFalse($modifie->required);
        self::assertSame('Vitrine essuyée', $modifie->text(Locale::Fr));
    }
}
