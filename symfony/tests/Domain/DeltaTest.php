<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\Delta;
use Merisu\Inventory\Domain\EveningValidation;
use Merisu\Inventory\Domain\NetVariance;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Domain\Rounding;
use Merisu\Inventory\Domain\RoundingMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeltaTest extends TestCase
{
    /** @var array<string, array<string,float>> */
    private array $recipes = [
        'p1' => ['lait' => 0.2, 'sucre' => 0.05],
        'p2' => ['lait' => 0.1, 'cafe' => 0.008],
    ];

    #[Test]
    public function multiplie_les_pieces_produites_par_la_quantite_unitaire(): void
    {
        $result = Delta::theoreticalConsumption(['p1' => 100.0], $this->recipes);

        self::assertEqualsWithDelta(20.0, $result['byMaterial']['lait'], 1e-9);
        self::assertEqualsWithDelta(5.0, $result['byMaterial']['sucre'], 1e-9);
    }

    #[Test]
    public function cumule_une_matiere_utilisee_par_plusieurs_produits(): void
    {
        $result = Delta::theoreticalConsumption(['p1' => 100.0, 'p2' => 50.0], $this->recipes);

        self::assertEqualsWithDelta(25.0, $result['byMaterial']['lait'], 1e-9);
        self::assertEqualsWithDelta(0.4, $result['byMaterial']['cafe'], 1e-9);
    }

    #[Test]
    public function signale_les_produits_sans_recette(): void
    {
        $result = Delta::theoreticalConsumption(['p1' => 10.0, 'inconnu' => 40.0], $this->recipes);

        self::assertSame(['inconnu'], $result['productsWithoutRecipe']);
        self::assertEqualsWithDelta(2.0, $result['byMaterial']['lait'], 1e-9);
    }

    #[Test]
    public function ignore_un_produit_sans_recette_mais_sans_production(): void
    {
        self::assertSame([], Delta::theoreticalConsumption(['inconnu' => 0.0], $this->recipes)['productsWithoutRecipe']);
    }

    #[Test]
    public function calcule_delta_et_pourcentage(): void
    {
        $delta = Delta::materialDeltas(['lait' => 20.0], ['lait' => 22.0], 0.05)[0];

        self::assertSame(2.0, $delta->delta);
        self::assertEqualsWithDelta(0.1, $delta->deltaPct, 1e-9);
        self::assertTrue($delta->outOfTolerance);
    }

    #[Test]
    public function reste_dans_la_tolerance_pour_un_petit_ecart(): void
    {
        $delta = Delta::materialDeltas(['lait' => 20.0], ['lait' => 20.5], 0.05)[0];

        self::assertEqualsWithDelta(0.025, $delta->deltaPct, 1e-9);
        self::assertFalse($delta->outOfTolerance);
    }

    #[Test]
    public function traite_un_delta_negatif_avec_la_meme_tolerance(): void
    {
        $delta = Delta::materialDeltas(['lait' => 20.0], ['lait' => 16.0], 0.05)[0];

        self::assertSame(-4.0, $delta->delta);
        self::assertTrue($delta->outOfTolerance);
    }

    #[Test]
    public function ne_declenche_pas_la_tolerance_exactement_a_la_limite(): void
    {
        $delta = Delta::materialDeltas(['lait' => 100.0], ['lait' => 105.0], 0.05)[0];

        self::assertEqualsWithDelta(0.05, $delta->deltaPct, 1e-9);
        self::assertFalse($delta->outOfTolerance);
    }

    #[Test]
    public function gere_un_theorique_nul_avec_consommation_reelle(): void
    {
        $delta = Delta::materialDeltas(['lait' => 0.0], ['lait' => 3.0], 0.05)[0];

        self::assertSame(3.0, $delta->delta);
        self::assertNull($delta->deltaPct);
        self::assertTrue($delta->outOfTolerance);
    }

    #[Test]
    public function reste_neutre_quand_theorique_et_reel_sont_nuls(): void
    {
        self::assertFalse(Delta::materialDeltas(['lait' => 0.0], ['lait' => 0.0], 0.05)[0]->outOfTolerance);
    }

    #[Test]
    public function marque_partiel_quand_le_reel_est_inconnu(): void
    {
        $delta = Delta::materialDeltas(['lait' => 20.0], [], 0.05)[0];

        self::assertTrue($delta->partial);
        self::assertNull($delta->delta);
        self::assertFalse($delta->outOfTolerance);
    }

    #[Test]
    public function inclut_les_matieres_presentes_uniquement_cote_reel(): void
    {
        $deltas = Delta::materialDeltas(['lait' => 1.0], ['lait' => 1.0, 'sucre' => 2.0], 0.05);

        self::assertSame(['lait', 'sucre'], array_map(static fn ($d) => $d->materialId, $deltas));
        self::assertSame(0.0, $deltas[1]->theoreticalQty);
    }

    #[Test]
    public function resume_le_rapport(): void
    {
        $deltas = Delta::materialDeltas(['lait' => 20.0, 'sucre' => 10.0], ['lait' => 30.0], 0.05);

        self::assertSame(
            ['materials' => 2, 'outOfTolerance' => 1, 'partial' => true],
            Delta::summarize($deltas),
        );
    }
}
