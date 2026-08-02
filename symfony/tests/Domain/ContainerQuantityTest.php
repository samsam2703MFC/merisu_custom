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
    public function testSepareLesContenantsPleinsDeLaFraction(?float $qty, int $whole, int $percent): void
    {
        self::assertSame(['whole' => $whole, 'percent' => $percent], ContainerQuantity::split($qty));
    }

    public static function quantites(): iterable
    {
        yield 'aucune saisie' => [null, 0, 0];
        yield 'vide' => [0.0, 0, 0];
        yield 'un quart seul' => [0.25, 0, 25];
        yield 'deux bacs pleins' => [2.0, 2, 0];
        yield 'deux bacs et demi' => [2.5, 2, 50];
        yield 'trois quarts du troisième' => [2.75, 2, 75];
        yield 'un quart du troisième' => [2.25, 2, 25];
    }

    public function testUneQuantiteVenueDAilleursEstRameneeALaFractionLaPlusProche(): void
    {
        // Un import ou une saisie hors-ligne peut porter n'importe quelle
        // décimale. L'écran ne propose que quatre choix : il doit en présenter
        // un, pas rester muet.
        self::assertSame(['whole' => 1, 'percent' => 25], ContainerQuantity::split(1.3));
        self::assertSame(['whole' => 1, 'percent' => 50], ContainerQuantity::split(1.44));
        self::assertSame(['whole' => 1, 'percent' => 75], ContainerQuantity::split(1.7));
    }

    public function testUnResteArrondiAUnContenantPleinRemonteAuCompteur(): void
    {
        // 1,95 ne doit pas s'afficher « 1 bac + 100 % », choix qui n'existe pas.
        self::assertSame(['whole' => 2, 'percent' => 0], ContainerQuantity::split(1.95));
    }

    public function testUneQuantiteNegativeNeCasseRien(): void
    {
        self::assertSame(['whole' => 0, 'percent' => 0], ContainerQuantity::split(-3.0));
    }

    #[DataProvider('saisies')]
    public function testRecomposeLaQuantite(?int $whole, ?int $percent, ?float $attendu): void
    {
        self::assertSame($attendu, ContainerQuantity::combine($whole, $percent));
    }

    public static function saisies(): iterable
    {
        yield 'rien saisi' => [null, null, null];
        yield 'deux bacs pleins' => [2, 0, 2.0];
        yield 'deux et demi' => [2, 50, 2.5];
        yield 'une fraction seule' => [0, 75, 0.75];
        yield 'un contenant vide' => [0, 0, 0.0];
        yield 'compteur seul' => [3, null, 3.0];
    }

    public function testUnCompteurNegatifEstRameneAZero(): void
    {
        // La saisie vient d'un champ number : rien n'empêche un « -1 » collé.
        self::assertSame(0.5, ContainerQuantity::combine(-2, 50));
    }

    public function testLAllerRetourEstFidele(): void
    {
        // La propriété qui compte : ce que l'écran affiche, réenregistré sans
        // modification, doit rendre exactement la même quantité.
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0, 2.25, 7.5, 12.75] as $depart) {
            $part = ContainerQuantity::split($depart);
            self::assertSame(
                $depart,
                ContainerQuantity::combine($part['whole'], $part['percent']),
                "aller-retour sur $depart",
            );
        }
    }

    public function testLesFractionsProposeesRestentQuatre(): void
    {
        // Au poste on estime un niveau, on ne le mesure pas : ajouter des
        // choix allongerait la saisie sans gagner en justesse.
        self::assertSame([0, 25, 50, 75], ContainerQuantity::FRACTIONS);
    }

    public function testNimporteQuelPourcentageTombeSurUneFractionProposee(): void
    {
        foreach (range(0, 120) as $percent) {
            self::assertContains(
                ContainerQuantity::nearestFraction($percent),
                ContainerQuantity::FRACTIONS,
                "pourcentage $percent",
            );
        }
    }
}
