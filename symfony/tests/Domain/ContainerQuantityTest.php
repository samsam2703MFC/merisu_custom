<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ContainerQuantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La base stocke une quantité décimale, l'écran montre des contenants. Une
 * erreur de traduction entre les deux fausserait la variation nette, donc le
 * plan de production, donc ce qui est fabriqué le lendemain matin.
 */
final class ContainerQuantityTest extends TestCase
{
    #[DataProvider('quantites')]
    public function testSepareLesContenantsPleinsDeLaFraction(?float $qty, int $whole, float $percent): void
    {
        self::assertSame(['whole' => $whole, 'percent' => $percent], ContainerQuantity::split($qty));
    }

    public static function quantites(): iterable
    {
        yield 'aucune saisie' => [null, 0, 0.0];
        yield 'vide' => [0.0, 0, 0.0];
        yield 'un quart seul' => [0.25, 0, 25.0];
        yield 'deux bacs pleins' => [2.0, 2, 0.0];
        yield 'deux bacs et demi' => [2.5, 2, 50.0];
        yield 'trois quarts du troisième' => [2.75, 2, 75.0];
        yield 'un quart du troisième' => [2.25, 2, 25.0];
        // Les graduations qui n'existaient pas avant le passage au huitième.
        yield 'un huitième' => [0.125, 0, 12.5];
        yield 'trois huitièmes' => [1.375, 1, 37.5];
        yield 'cinq huitièmes' => [2.625, 2, 62.5];
        yield 'sept huitièmes' => [3.875, 3, 87.5];
    }

    public function testUneQuantiteVenueDAilleursEstRameneeALaFractionLaPlusProche(): void
    {
        // Un import ou une saisie hors-ligne peut porter n'importe quelle
        // décimale. L'écran ne propose que huit graduations : il doit en
        // présenter une, pas rester muet.
        self::assertSame(['whole' => 1, 'percent' => 25.0], ContainerQuantity::split(1.3));
        self::assertSame(['whole' => 1, 'percent' => 50.0], ContainerQuantity::split(1.44));
        self::assertSame(['whole' => 1, 'percent' => 75.0], ContainerQuantity::split(1.7));
        // Le huitième rapproche : un fond de bac à 10 % s'affichait « vide »
        // au quart le plus proche, ce qui le faisait passer pour un contenant
        // à jeter. Il vaut maintenant un huitième.
        self::assertSame(['whole' => 1, 'percent' => 12.5], ContainerQuantity::split(1.1));
    }

    public function testUnResteArrondiAUnContenantPleinRemonteAuCompteur(): void
    {
        // 1,95 ne doit pas s'afficher « 1 bac + 100 % », choix qui n'existe pas.
        self::assertSame(['whole' => 2, 'percent' => 0.0], ContainerQuantity::split(1.95));
    }

    public function testUneQuantiteNegativeNeCasseRien(): void
    {
        self::assertSame(['whole' => 0, 'percent' => 0.0], ContainerQuantity::split(-3.0));
    }

    #[DataProvider('saisies')]
    public function testRecomposeLaQuantite(?int $whole, ?float $percent, ?float $attendu): void
    {
        self::assertSame($attendu, ContainerQuantity::combine($whole, $percent));
    }

    public static function saisies(): iterable
    {
        yield 'rien saisi' => [null, null, null];
        yield 'deux bacs pleins' => [2, 0.0, 2.0];
        yield 'deux et demi' => [2, 50.0, 2.5];
        yield 'une fraction seule' => [0, 75.0, 0.75];
        yield 'un contenant vide' => [0, 0.0, 0.0];
        yield 'compteur seul' => [3, null, 3.0];
        yield 'un huitième seul' => [0, 12.5, 0.125];
        yield 'deux bacs et cinq huitièmes' => [2, 62.5, 2.625];
    }

    public function testUnCompteurNegatifEstRameneAZero(): void
    {
        // La saisie vient d'un champ number : rien n'empêche un « -1 » collé.
        self::assertSame(0.5, ContainerQuantity::combine(-2, 50.0));
    }

    public function testLAllerRetourEstFidele(): void
    {
        // La propriété qui compte : ce que l'écran affiche, réenregistré sans
        // modification, doit rendre exactement la même quantité.
        foreach ([0.0, 0.125, 0.25, 0.375, 0.5, 0.625, 0.75, 0.875, 1.0, 2.25, 7.5, 12.875] as $depart) {
            $part = ContainerQuantity::split($depart);
            self::assertSame(
                $depart,
                ContainerQuantity::combine($part['whole'], $part['percent']),
                "aller-retour sur $depart",
            );
        }
    }

    public function testLesGraduationsVontParHuitiemes(): void
    {
        // Le huitième est le plus petit pas encore estimable à l'œil : c'est
        // la moitié d'un quart, repérable à mi-chemin entre deux traits. Le
        // dixième demanderait de mesurer.
        self::assertSame([0.0, 12.5, 25.0, 37.5, 50.0, 62.5, 75.0, 87.5], ContainerQuantity::FRACTIONS);
    }

    public function testAucuneGraduationNeVaut100(): void
    {
        // 100 % d'un contenant, c'est un contenant plein : cela relève du
        // compteur. Le proposer ici ouvrirait deux façons de saisir la même
        // chose, et le total serait compté deux fois un jour ou l'autre.
        self::assertNotContains(100.0, ContainerQuantity::FRACTIONS);
        self::assertSame(87.5, ContainerQuantity::nearestFraction(100.0));
        self::assertSame(87.5, ContainerQuantity::nearestFraction(140.0));
    }

    public function testNimporteQuelPourcentageTombeSurUneFractionProposee(): void
    {
        foreach (range(0, 120) as $percent) {
            self::assertContains(
                ContainerQuantity::nearestFraction((float) $percent),
                ContainerQuantity::FRACTIONS,
                "pourcentage $percent",
            );
        }
    }
}
