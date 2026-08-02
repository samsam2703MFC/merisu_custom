<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Adapter\ShopPerformance;
use Merisu\Inventory\Domain\RankingMetric;
use Merisu\Inventory\Domain\ShopRanking;
use PHPUnit\Framework\TestCase;

/**
 * Un classement affiché aux équipes doit être défendable. Deux pièges le
 * rendraient faux sans que personne ne s'en aperçoive : additionner des
 * devises différentes, et changer d'ordre à chaque affichage.
 */
final class ShopRankingTest extends TestCase
{
    private function shop(
        string $id,
        string $pays,
        float $ca,
        int $clients = 0,
        int $tiramisu = 0,
        string $devise = 'EUR',
    ): ShopPerformance {
        return new ShopPerformance($id, 'Merisù ' . $id, $pays, $ca, $clients, $tiramisu, $devise);
    }

    public function testClasseDuPlusGrandAuPlusPetit(): void
    {
        $rangs = ShopRanking::build([
            $this->shop('b', 'FR', 100.0),
            $this->shop('a', 'FR', 300.0),
            $this->shop('c', 'FR', 200.0),
        ], RankingMetric::Revenue);

        self::assertSame(['a', 'c', 'b'], array_map(static fn (array $l): string => $l['shop']->id, $rangs));
        self::assertSame([1, 2, 3], array_map(static fn (array $l): int => $l['rank'], $rangs));
    }

    public function testLesExAequoPartagentLeRangEtLeSuivantSaute(): void
    {
        // Convention sportive : deux premières ex æquo sont suivies d'une
        // TROISIÈME, pas d'une seconde. C'est ce que le lecteur attend.
        $rangs = ShopRanking::build([
            $this->shop('a', 'FR', 300.0),
            $this->shop('b', 'FR', 300.0),
            $this->shop('c', 'FR', 100.0),
        ], RankingMetric::Revenue);

        self::assertSame([1, 1, 3], array_map(static fn (array $l): int => $l['rank'], $rangs));
    }

    public function testLOrdreEstStableEntreDeuxAffichages(): void
    {
        // À égalité, l'ordre alphabétique tranche. Sans cela le classement
        // changerait d'un rechargement à l'autre, et paraîtrait truqué.
        $boutiques = [
            new ShopPerformance('z', 'Merisù Zola', 'FR', 300.0, 0, 0),
            new ShopPerformance('a', 'Merisù Arc', 'FR', 300.0, 0, 0),
        ];

        $premier = ShopRanking::build($boutiques, RankingMetric::Revenue);
        $second = ShopRanking::build(array_reverse($boutiques), RankingMetric::Revenue);

        self::assertSame('Merisù Arc', $premier[0]['shop']->name);
        self::assertSame('Merisù Arc', $second[0]['shop']->name);
    }

    public function testLeClassementNationalNeRetientQueLePays(): void
    {
        $rangs = ShopRanking::build([
            $this->shop('pl1', 'PL', 100.0, 0, 0, 'PLN'),
            $this->shop('fr1', 'FR', 900.0),
            $this->shop('pl2', 'PL', 200.0, 0, 0, 'PLN'),
        ], RankingMetric::Revenue, 'pl1', 'PL');

        self::assertCount(2, $rangs);
        self::assertSame(['pl2', 'pl1'], array_map(static fn (array $l): string => $l['shop']->id, $rangs));
    }

    public function testLeChiffreDAffairesNeMelangeJamaisLesDevises(): void
    {
        // 1 000 PLN ne valent pas 1 000 EUR. Sans taux fiable, le seul
        // classement honnête est celui de la devise de la boutique courante.
        $rangs = ShopRanking::build([
            $this->shop('pl', 'PL', 5000.0, 0, 0, 'PLN'),
            $this->shop('fr', 'FR', 900.0, 0, 0, 'EUR'),
            $this->shop('it', 'IT', 800.0, 0, 0, 'EUR'),
        ], RankingMetric::Revenue, 'fr');

        self::assertSame(['fr', 'it'], array_map(static fn (array $l): string => $l['shop']->id, $rangs));
    }

    public function testClientsEtTiramisuSeComparentEntreDevises(): void
    {
        // Des clients et des tiramisu se comptent à l'identique partout : rien
        // ne justifie d'écarter une boutique parce qu'elle facture en zlotys.
        $rangs = ShopRanking::build([
            $this->shop('pl', 'PL', 0.0, 400, 900, 'PLN'),
            $this->shop('fr', 'FR', 0.0, 500, 300, 'EUR'),
        ], RankingMetric::Customers, 'fr');

        self::assertCount(2, $rangs);
        self::assertSame('fr', $rangs[0]['shop']->id);

        $parTiramisu = ShopRanking::build([
            $this->shop('pl', 'PL', 0.0, 400, 900, 'PLN'),
            $this->shop('fr', 'FR', 0.0, 500, 300, 'EUR'),
        ], RankingMetric::TiramisuSold, 'fr');

        self::assertSame('pl', $parTiramisu[0]['shop']->id);
    }

    public function testLaBoutiqueCouranteEstSignalee(): void
    {
        $rangs = ShopRanking::build([
            $this->shop('a', 'FR', 300.0),
            $this->shop('b', 'FR', 100.0),
        ], RankingMetric::Revenue, 'b');

        self::assertFalse($rangs[0]['isCurrent']);
        self::assertTrue($rangs[1]['isCurrent']);
    }

    public function testPositionDansLeClassement(): void
    {
        $rangs = ShopRanking::build([
            $this->shop('a', 'FR', 300.0),
            $this->shop('b', 'FR', 200.0),
            $this->shop('c', 'FR', 100.0),
        ], RankingMetric::Revenue, 'b');

        self::assertSame(['rank' => 2, 'total' => 3], ShopRanking::positionOf($rangs, 'b'));
        self::assertNull(ShopRanking::positionOf($rangs, 'inconnue'));
        self::assertNull(ShopRanking::positionOf($rangs, null));
    }

    public function testSansBoutiqueCouranteLeClassementResteAffichable(): void
    {
        // L'adaptateur peut ignorer quelle boutique tient le poste : l'écran
        // doit alors montrer le classement sans mettre personne en avant.
        $rangs = ShopRanking::build([
            $this->shop('a', 'FR', 300.0),
            $this->shop('b', 'FR', 100.0),
        ], RankingMetric::Revenue, null);

        self::assertCount(2, $rangs);
        self::assertFalse($rangs[0]['isCurrent']);
    }

    public function testUnReseauVideNeCassePas(): void
    {
        self::assertSame([], ShopRanking::build([], RankingMetric::Revenue, 'a', 'FR'));
        self::assertSame([], ShopRanking::countries([]));
    }

    public function testLesPaysSontDedoublonnesEtTries(): void
    {
        self::assertSame(['FR', 'IT', 'PL'], ShopRanking::countries([
            $this->shop('a', 'PL', 0.0),
            $this->shop('b', 'fr', 0.0),
            $this->shop('c', 'IT', 0.0),
            $this->shop('d', 'PL', 0.0),
        ]));
    }

    public function testLePanierMoyenNeDiviseJamaisParZero(): void
    {
        self::assertNull($this->shop('a', 'FR', 500.0, 0)->averageBasket());
        self::assertSame(5.0, $this->shop('b', 'FR', 500.0, 100)->averageBasket());
    }
}
