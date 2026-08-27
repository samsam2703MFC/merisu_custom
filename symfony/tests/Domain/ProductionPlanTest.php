<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ProductionForecast;
use PHPUnit\Framework\TestCase;

/**
 * Le plan de production : ce qui entre dedans, et ce qui n'y entre pas.
 *
 * Les cas qui comptent sont ceux où la caisse vend autre chose que des
 * gâteaux — un frais de service, une livraison, un sac. Le bon qui demande de
 * fabriquer trois livraisons perd sa crédibilité entière.
 */
final class ProductionPlanTest extends TestCase
{
    /** Deux jeudis observés, avec des lignes qui ne se fabriquent pas. */
    private const VENTES = [
        '2026-08-13' => ['tira' => 100.0, 'livraison' => 8.0, 'sac' => 20.0],
        '2026-08-20' => ['tira' => 120.0, 'livraison' => 12.0, 'sac' => 20.0],
    ];

    /** @param list<string>|null $produites */
    private static function plan(?array $produites): ProductionForecast
    {
        return ProductionForecast::build(
            self::VENTES,
            [],
            [],
            [],
            ['2026-08-27'], // un jeudi
            0.0,
            ['tira' => 'Tiramisu', 'livraison' => 'Dostawa', 'sac' => 'Sac'],
            $produites,
        );
    }

    public function testSansFiltreToutCompte(): void
    {
        // 128 et 152 → moyenne 140
        $plan = self::plan(null);

        self::assertSame(140.0, $plan->days[0]->base);
        self::assertSame(140, $plan->days[0]->pieces);
    }

    /**
     * LE cas : on ne fabrique pas une livraison.
     *
     * La base tombe aux seuls tiramisu — 100 et 120, donc 110 — et le partage
     * ne propose plus ni sac ni livraison.
     */
    public function testUneLigneQuiNeSeFabriquePasSortDuPlan(): void
    {
        $plan = self::plan(['tira']);

        self::assertSame(110.0, $plan->days[0]->base);
        self::assertSame(110, $plan->days[0]->pieces);
        self::assertSame(['tira'], array_keys($plan->mix));
    }

    public function testLeBonNeListeQueCeQuiSeFabrique(): void
    {
        $lignes = self::plan(['tira'])->topProducts(110);

        self::assertCount(1, $lignes);
        self::assertSame('Tiramisu', $lignes[0]['name']);
        self::assertSame(110, $lignes[0]['pieces']);
    }

    /**
     * Une liste blanche VIDE ne doit pas être confondue avec « pas de
     * filtre » : elle décrit un catalogue où rien ne se fabrique, et le plan
     * doit alors être vide plutôt que complet.
     */
    public function testUneListeBlancheVideNeProduitRien(): void
    {
        $plan = self::plan([]);

        self::assertSame(0.0, $plan->days[0]->base);
        self::assertSame(0, $plan->days[0]->pieces);
        self::assertSame([], $plan->mix);
    }

    /**
     * Le palmarès est rendu en LISTE.
     *
     * En table indexée, `array_slice` renumérotait les références numériques :
     * l'écran cherchait une référence « 0 » qui n'existait nulle part, et la
     * page tombait en erreur.
     */
    public function testLePalmaresEstUneListeQuiSurvitAuDecoupage(): void
    {
        $plan = ProductionForecast::build(
            ['2026-08-13' => ['12' => 60.0, '34' => 40.0]],
            [], [], [],
            ['2026-08-27'],
            0.0,
            ['12' => 'Douze', '34' => 'Trente-quatre'],
        );

        $lignes = $plan->topProducts(100, 1);

        self::assertCount(1, $lignes);
        self::assertSame('12', $lignes[0]['ref']);
        self::assertSame('Douze', $lignes[0]['name']);
        self::assertSame(60.0, $lignes[0]['share']);
    }

    /** Une part qui ne fait pas une pièce entière n'encombre pas le bon. */
    public function testUnProduitSansPieceEntiereNEstPasListe(): void
    {
        $plan = ProductionForecast::build(
            ['2026-08-13' => ['gros' => 999.0, 'miette' => 1.0]],
            [], [], [],
            ['2026-08-27'],
            0.0,
            ['gros' => 'Gros', 'miette' => 'Miette'],
        );

        $refs = array_column($plan->topProducts(10), 'ref');

        self::assertSame(['gros'], $refs);
    }
}
