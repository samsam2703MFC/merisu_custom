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

final class EveningValidationTest extends TestCase
{
    /** @return list<Product> */
    private function products(int $count = 2): array
    {
        $products = [];
        for ($i = 1; $i <= $count; ++$i) {
            $products[] = new Product("p$i", "PRODUIT_$i", ['fr' => "Slot $i"], 'pcs', true, 0, 1, RoundingMode::Ceil, null, $i);
        }

        return $products;
    }

    #[Test]
    public function valide_quand_tous_les_produits_actifs_sont_saisis(): void
    {
        $result = EveningValidation::validate($this->products(), ['p1' => 10.0, 'p2' => 0.0], [], 0, false, false, false);

        self::assertTrue($result->valid);
    }

    #[Test]
    public function refuse_quand_un_produit_actif_n_est_pas_saisi(): void
    {
        $result = EveningValidation::validate($this->products(), ['p1' => 10.0], [], 0, false, false, false);

        self::assertFalse($result->valid);
        self::assertSame(['p2'], $result->productIdsInError());
        self::assertSame('MISSING_QTY', $result->issues[0]->code);
    }

    #[Test]
    public function accepte_un_stock_a_zero_qui_n_est_pas_une_absence_de_saisie(): void
    {
        self::assertTrue(EveningValidation::validate($this->products(1), ['p1' => 0.0], [], 0, false, false, false)->valid);
    }

    #[Test]
    public function refuse_une_quantite_negative(): void
    {
        $result = EveningValidation::validate($this->products(1), ['p1' => -3.0], [], 0, false, false, false);

        self::assertSame('NEGATIVE_QTY', $result->issues[0]->code);
    }

    #[Test]
    public function exige_une_photo_par_produit_quand_l_admin_l_impose(): void
    {
        $result = EveningValidation::validate($this->products(), ['p1' => 5.0, 'p2' => 5.0], ['p1' => 1], 0, true, true, false);

        self::assertFalse($result->valid);
        self::assertSame('MISSING_PHOTO', $result->issues[0]->code);
        self::assertSame('p2', $result->issues[0]->productId);
    }

    #[Test]
    public function se_contente_d_une_photo_globale_quand_l_admin_le_permet(): void
    {
        self::assertTrue(
            EveningValidation::validate($this->products(), ['p1' => 5.0, 'p2' => 5.0], [], 1, true, false, false)->valid,
        );
    }

    #[Test]
    public function accepte_une_photo_rattachee_a_un_produit_comme_preuve_globale(): void
    {
        self::assertTrue(
            EveningValidation::validate($this->products(), ['p1' => 5.0, 'p2' => 5.0], ['p1' => 2], 0, true, false, false)->valid,
        );
    }

    #[Test]
    public function refuse_en_l_absence_totale_de_photo(): void
    {
        $result = EveningValidation::validate($this->products(), ['p1' => 5.0, 'p2' => 5.0], [], 0, true, false, false);

        self::assertSame('MISSING_GLOBAL_PHOTO', $result->issues[0]->code);
    }

    #[Test]
    public function refuse_une_double_validation(): void
    {
        $result = EveningValidation::validate($this->products(1), ['p1' => 5.0], [], 0, false, false, true);

        self::assertSame('ALREADY_VALIDATED', $result->issues[0]->code);
    }

    #[Test]
    public function verrouille_le_consultant_mais_pas_l_admin(): void
    {
        self::assertTrue(EveningValidation::canEdit(false, Role::Consultant));
        self::assertFalse(EveningValidation::canEdit(true, Role::Consultant));
        self::assertTrue(EveningValidation::canEdit(true, Role::Admin));
    }
}
