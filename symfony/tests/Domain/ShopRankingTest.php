<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Adapter\ShopPerformance;
use Merisu\Inventory\Domain\RankingMetric;
use Merisu\Inventory\Domain\ShopRanking;
use PHPUnit\Framework\TestCase;

/**
 * Un classement affiché aux équipes doit être défendable. Le piège qui le
 * rendrait faux sans que personne ne s'en aperçoive : changer d'ordre à
 * chaque affichage, ce qui le ferait passer pour truqué.
 */
final class ShopRankingTest extends TestCase
{
    /** Le troisième paramètre est la valeur classée : les tiramisu vendus. */
    private function shop(
        string $id,
        string $pays,
        int $tiramisu = 0,
        int $clients = 0,
        float $ca = 0.0,
        string $devise = 'EUR',
    ): ShopPerformance {
        return new ShopPerformance($id, 'Merisù ' . $id, $pays, $ca, $clients, $tiramisu, $devise);
    }

    public function testClasseDuPlusGrandAuPlusPetit(): void
    {
        $rangs = ShopRanking::build([
            $this->shop('b', 'FR', 100),
            $this->shop('a', 'FR', 300),
            $this->shop('c', 'FR', 200),
        ], RankingMetric::TiramisuSold);

        self::assertSame(['a', 'c', 'b'], array_map(static fn (array $l): string => $l['shop']->id, $rangs));
        self::assertSame([1, 2, 3], array_map(static fn (array $l): int => $l['rank'], $rangs));
    }

    public function testLesExAequoPartagentLeRangEtLeSuivantSaute(): void
    {
        // Convention sportive : deux premières ex æquo sont suivies d'une
        // TROISIÈME, pas d'une seconde. C'est ce que le lecteur attend.
        $rangs = ShopRanking::build([
            $this->shop('a', 'FR', 300),
            $this->shop('b', 'FR', 300),
            $this->shop('c', 'FR', 100),
        ], RankingMetric::TiramisuSold);

        self::assertSame([1, 1, 3], array_map(static fn (array $l): int => $l['rank'], $rangs));
    }

    public function testLOrdreEstStableEntreDeuxAffichages(): void
    {
        // À égalité, l'ordre alphabétique tranche. Sans cela le classement
        // changerait d'un rechargement à l'autre, et paraîtrait truqué.
        $boutiques = [
            new ShopPerformance('z', 'Merisù Zola', 'FR', 0.0, 0, 300),
            new ShopPerformance('a', 'Merisù Arc', 'FR', 0.0, 0, 300),
        ];

        $premier = ShopRanking::build($boutiques, RankingMetric::TiramisuSold);
        $second = ShopRanking::build(array_reverse($boutiques), RankingMetric::TiramisuSold);

        self::assertSame('Merisù Arc', $premier[0]['shop']->name);
        self::assertSame('Merisù Arc', $second[0]['shop']->name);
    }

    public function testLeClassementNationalNeRetientQueLePays(): void
    {
        $rangs = ShopRanking::build([
            $this->shop('pl1', 'PL', 100, 0, 0.0, 'PLN'),
            $this->shop('fr1', 'FR', 900),
            $this->shop('pl2', 'PL', 200, 0, 0.0, 'PLN'),
        ], RankingMetric::TiramisuSold, 'pl1', 'PL');

        self::assertCount(2, $rangs);
        self::assertSame(['pl2', 'pl1'], array_map(static fn (array $l): string => $l['shop']->id, $rangs));
    }

    public function testClientsEtTiramisuSeComparentEntreDevises(): void
    {
        // La raison d'être des deux seules mesures retenues : des clients et
        // des tiramisu se comptent à l'identique partout, alors qu'un chiffre
        // d'affaires en zlotys ne se compare pas à un chiffre en euros.
        $rangs = ShopRanking::build([
            $this->shop('pl', 'PL', 900, 400, 0.0, 'PLN'),
            $this->shop('fr', 'FR', 300, 500, 0.0, 'EUR'),
        ], RankingMetric::Customers, 'fr');

        self::assertCount(2, $rangs);
        self::assertSame('fr', $rangs[0]['shop']->id);

        $parTiramisu = ShopRanking::build([
            $this->shop('pl', 'PL', 900, 400, 0.0, 'PLN'),
            $this->shop('fr', 'FR', 300, 500, 0.0, 'EUR'),
        ], RankingMetric::TiramisuSold, 'fr');

        self::assertSame('pl', $parTiramisu[0]['shop']->id);
    }

    public function testLaBoutiqueCouranteEstSignalee(): void
    {
        $rangs = ShopRanking::build([
            $this->shop('a', 'FR', 300),
            $this->shop('b', 'FR', 100),
        ], RankingMetric::TiramisuSold, 'b');

        self::assertFalse($rangs[0]['isCurrent']);
        self::assertTrue($rangs[1]['isCurrent']);
    }

    public function testPositionDansLeClassement(): void
    {
        $rangs = ShopRanking::build([
            $this->shop('a', 'FR', 300),
            $this->shop('b', 'FR', 200),
            $this->shop('c', 'FR', 100),
        ], RankingMetric::TiramisuSold, 'b');

        self::assertSame(['rank' => 2, 'total' => 3], ShopRanking::positionOf($rangs, 'b'));
        self::assertNull(ShopRanking::positionOf($rangs, 'inconnue'));
        self::assertNull(ShopRanking::positionOf($rangs, null));
    }

    public function testSansBoutiqueCouranteLeClassementResteAffichable(): void
    {
        // L'adaptateur peut ignorer quelle boutique tient le poste : l'écran
        // doit alors montrer le classement sans mettre personne en avant.
        $rangs = ShopRanking::build([
            $this->shop('a', 'FR', 300),
            $this->shop('b', 'FR', 100),
        ], RankingMetric::TiramisuSold, null);

        self::assertCount(2, $rangs);
        self::assertFalse($rangs[0]['isCurrent']);
    }

    public function testUnReseauVideNeCassePas(): void
    {
        self::assertSame([], ShopRanking::build([], RankingMetric::TiramisuSold, 'a', 'FR'));
        self::assertSame([], ShopRanking::countries([]));
    }

    public function testLesPaysSontDedoublonnesEtTries(): void
    {
        self::assertSame(['FR', 'IT', 'PL'], ShopRanking::countries([
            $this->shop('a', 'PL'),
            $this->shop('b', 'fr'),
            $this->shop('c', 'IT'),
            $this->shop('d', 'PL'),
        ]));
    }

    public function testLePanierMoyenNeDiviseJamaisParZero(): void
    {
        self::assertNull($this->shop('a', 'FR', 0, 0, 500.0)->averageBasket());
        self::assertSame(5.0, $this->shop('b', 'FR', 0, 100, 500.0)->averageBasket());
    }
}
