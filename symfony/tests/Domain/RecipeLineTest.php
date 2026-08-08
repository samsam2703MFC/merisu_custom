<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\RecipeLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecipeLineTest extends TestCase
{
    // ── Ramener une fournée à l'unité ───────────────────────────────────────

    /** @return iterable<string, array{float, float, float}> */
    public static function fournees(): iterable
    {
        yield '4 l pour 20 parts' => [4.0, 20.0, 0.2];
        yield 'déjà par unité' => [0.25, 1.0, 0.25];
        yield 'une seule part' => [0.3, 1.0, 0.3];
        yield 'rendement fractionnaire' => [1.0, 2.5, 0.4];
        yield 'quantité nulle' => [0.0, 20.0, 0.0];
    }

    #[DataProvider('fournees')]
    public function testUneFourneeSeRameneALUnite(float $quantite, float $rendement, float $attendu): void
    {
        self::assertEqualsWithDelta($attendu, RecipeLine::perUnit($quantite, $rendement), 0.000001);
    }

    public function testLeRendementVautUnParDefaut(): void
    {
        self::assertSame(0.35, RecipeLine::perUnit(0.35));
    }

    /** @return iterable<string, array{float, float}> */
    public static function saisiesFautives(): iterable
    {
        yield 'rendement nul' => [4.0, 0.0];
        yield 'rendement négatif' => [4.0, -20.0];
        yield 'quantité négative' => [-4.0, 20.0];
        yield 'quantité infinie' => [\INF, 20.0];
        yield 'rendement infini' => [4.0, \INF];
    }

    /**
     * Null plutôt que zéro : une ligne à zéro dirait « ce produit ne consomme
     * pas de lait », ce qui est une affirmation, quand une saisie fautive
     * n'affirme rien.
     */
    #[DataProvider('saisiesFautives')]
    public function testUneSaisieFautiveNAffirmeRien(float $quantite, float $rendement): void
    {
        self::assertNull(RecipeLine::perUnit($quantite, $rendement));
    }

    // ── Mise à plat ─────────────────────────────────────────────────────────

    public function testLaMiseAPlatIndexeParProduitPuisMatiere(): void
    {
        $plat = RecipeLine::flatten([
            new RecipeLine('p1', 'mat-lait', 0.2),
            new RecipeLine('p1', 'mat-sucre', 0.05),
            new RecipeLine('p2', 'mat-cafe', 0.008),
        ]);

        self::assertSame([
            'p1' => ['mat-lait' => 0.2, 'mat-sucre' => 0.05],
            'p2' => ['mat-cafe' => 0.008],
        ], $plat);
    }

    /**
     * « 0,1 l de lait dans la crème, 0,1 l dans le nappage » fait bien 0,2 l.
     * Écraser aurait perdu la moitié de la consommation, et le delta technique
     * aurait accusé l'atelier d'un vol qu'il n'a pas commis.
     */
    public function testUneMatiereCiteeDeuxFoisSAdditionne(): void
    {
        $plat = RecipeLine::flatten([
            new RecipeLine('p1', 'mat-lait', 0.1),
            new RecipeLine('p1', 'mat-lait', 0.1),
        ]);

        self::assertEqualsWithDelta(0.2, $plat['p1']['mat-lait'], 0.000001);
    }

    public function testAucuneLigneDonneAucuneNomenclature(): void
    {
        self::assertSame([], RecipeLine::flatten([]));
    }

    /** La forme rendue est celle qu'attend `RecipeServiceInterface::recipes()`. */
    public function testLaFormeEstCelleAttendueParLAdaptateur(): void
    {
        $plat = RecipeLine::flatten([new RecipeLine('p1', 'm1', 1.5)]);

        self::assertIsArray($plat['p1']);
        self::assertIsFloat($plat['p1']['m1']);
        self::assertSame(1.5, $plat['p1']['m1']);
    }
}
