<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\Checklist;
use Merisu\Inventory\Domain\ChecklistItem;
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
        return new ChecklistItem('ouverture-1', 'OPENING', $labels, 1, true);
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

    /**
     * Les volets sont devenus des DONNÉES : ces trois tests remplaçent ceux de
     * l'énumération disparue. Ce qui reste à garantir, c'est le nom multilingue
     * et son repli — le même contrat que les libellés de produits.
     */
    public function testUneChecklistSeNommeDansLaLangueDemandee(): void
    {
        $liste = new Checklist('CL-1', ['fr' => 'Réception', 'pl' => 'Przyjęcie']);

        self::assertSame('Réception', $liste->text(Locale::Fr));
        self::assertSame('Przyjęcie', $liste->text(Locale::Pl));
        // Langue absente : repli sur la langue par défaut, comme les produits.
        self::assertSame('Réception', $liste->text(Locale::It));
    }

    public function testUneChecklistSansAucunNomRendSonIdentifiant(): void
    {
        // Jamais une ligne vide : l'administrateur doit voir qu'un nom manque.
        self::assertSame('CL-2', (new Checklist('CL-2', []))->text(Locale::Fr));
    }

    public function testLHeureDUneChecklistEstFacultative(): void
    {
        self::assertFalse((new Checklist('CL-3', ['fr' => 'Qualité']))->hasExecutionTime());
        self::assertTrue((new Checklist('CL-4', ['fr' => 'Ouverture'], executionTime: '08:00'))->hasExecutionTime());
    }

    public function testLesIconesProposeesSontCellesQueLApplicationDessine(): void
    {
        // Une icône hors liste afficherait un carré vide sur la carte du
        // vendeur ; la liste est close et non vide, c'est tout son contrat.
        self::assertNotEmpty(Checklist::icons());
        self::assertContains('checklist', Checklist::icons());
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
