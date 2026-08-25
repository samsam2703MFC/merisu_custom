<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\PosSale;
use Merisu\Inventory\Domain\SalesBreakdown;
use Merisu\Inventory\Domain\SalesPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SalesBreakdownTest extends TestCase
{
    private static function vente(string $date, string $ref, float $qte, float $recette = 0.0): PosSale
    {
        return new PosSale($date, $ref, 'Produit ' . $ref, $qte, $recette);
    }

    // ── Les clés de période ─────────────────────────────────────────────────

    #[DataProvider('cles')]
    public function testChaqueDateSeRangeSousLaBonneCle(SalesPeriod $periode, string $date, string $attendu): void
    {
        self::assertSame($attendu, $periode->keyFor($date));
    }

    /** @return iterable<string, array{SalesPeriod, string, string}> */
    public static function cles(): iterable
    {
        yield 'jour' => [SalesPeriod::Day, '2026-08-24', '2026-08-24'];
        yield 'lundi' => [SalesPeriod::Weekday, '2026-08-24', '1'];
        yield 'dimanche' => [SalesPeriod::Weekday, '2026-08-23', '7'];
        yield 'mois' => [SalesPeriod::Month, '2026-08-24', '2026-08'];
        yield 'semaine' => [SalesPeriod::Week, '2026-08-24', '2026-W35'];
        // La semaine ISO appartient à l'année qui contient son jeudi. Sans
        // l'année, la semaine 1 de deux années se serait mélangée au premier
        // janvier — et le total de janvier aurait avalé celui de décembre.
        yield 'premier janvier rattaché à l année précédente' => [SalesPeriod::Week, '2027-01-01', '2026-W53'];
        yield 'trente et un décembre' => [SalesPeriod::Week, '2024-12-31', '2025-W01'];
    }

    // ── L'agrégation ────────────────────────────────────────────────────────

    public function testLesVentesSAdditionnentSousLeurCle(): void
    {
        $cases = SalesBreakdown::of([
            self::vente('2026-08-24', 'a', 10.0, 100.0),
            self::vente('2026-08-24', 'b', 5.0, 50.0),
            self::vente('2026-08-25', 'a', 3.0, 30.0),
        ], SalesPeriod::Day);

        self::assertCount(2, $cases);
        // Le plus récent d'abord : on regarde ce qui vient de se passer.
        self::assertSame('2026-08-25', $cases[0]->key);
        self::assertSame(3.0, $cases[0]->quantity);
        self::assertSame(15.0, $cases[1]->quantity);
        self::assertSame(150.0, $cases[1]->revenue);
    }

    /**
     * LE point de tout l'écran.
     *
     * « 716 le dimanche » ne veut rien dire tant qu'on ignore combien de
     * dimanches l'intervalle contient. Sur six semaines c'est 119 par
     * dimanche ; le total seul aurait fait produire six fois trop.
     */
    public function testLaMoyenneParJourDivisePARLESJOURSOBSERVES(): void
    {
        $ventes = [];
        foreach (['2026-08-02', '2026-08-09', '2026-08-16'] as $dimanche) {
            $ventes[] = self::vente($dimanche, 'a', 100.0);
        }

        $cases = SalesBreakdown::of($ventes, SalesPeriod::Weekday);

        self::assertCount(1, $cases);
        self::assertSame('7', $cases[0]->key);
        self::assertSame(300.0, $cases[0]->quantity);
        self::assertSame(3, $cases[0]->days);
        self::assertSame(100.0, $cases[0]->averagePerDay());
    }

    /**
     * Quarante produits vendus le même samedi font UN samedi, pas quarante.
     *
     * Compter les lignes au lieu des dates aurait divisé la moyenne par
     * quarante, et le plan de production avec elle.
     */
    public function testUnJourNeCompteQuUneFoisQuelQueSoitLeNombreDeProduits(): void
    {
        $cases = SalesBreakdown::of([
            self::vente('2026-08-22', 'a', 10.0),
            self::vente('2026-08-22', 'b', 10.0),
            self::vente('2026-08-22', 'c', 10.0),
        ], SalesPeriod::Weekday);

        self::assertSame(1, $cases[0]->days);
        self::assertSame(30.0, $cases[0]->averagePerDay());
    }

    /**
     * Aucune journée n'est inventée.
     *
     * Un lundi férié ne devient pas une case à zéro : la moyenne des lundis
     * n'est pas tirée vers le bas par un jour où la boutique était fermée.
     */
    public function testAucuneJourneeNEstInventee(): void
    {
        $cases = SalesBreakdown::of([self::vente('2026-08-22', 'a', 10.0)], SalesPeriod::Weekday);

        self::assertCount(1, $cases);
        self::assertSame('6', $cases[0]->key);
    }

    /** Le jour de semaine se lit dans l'ordre de la semaine, pas à l'envers. */
    public function testLesJoursDeSemaineVontDuLundiAuDimanche(): void
    {
        $cases = SalesBreakdown::of([
            self::vente('2026-08-23', 'a', 1.0),
            self::vente('2026-08-24', 'a', 1.0),
            self::vente('2026-08-22', 'a', 1.0),
        ], SalesPeriod::Weekday);

        self::assertSame(['1', '6', '7'], array_map(static fn ($c): string => $c->key, $cases));
    }

    /**
     * Le mois est par construction la somme de ses jours.
     *
     * C'est la raison d'être de l'agrégation locale : quatre appels à la
     * caisse, faits à quatre instants sur un jeu de commandes qui bouge,
     * n'auraient pas eu cette propriété.
     */
    public function testLeMoisEstLaSommeDeSesJours(): void
    {
        $ventes = [];
        foreach (['2026-08-01', '2026-08-15', '2026-08-31'] as $jour) {
            $ventes[] = self::vente($jour, 'a', 7.0, 70.0);
        }

        $jours = SalesBreakdown::of($ventes, SalesPeriod::Day);
        $mois = SalesBreakdown::of($ventes, SalesPeriod::Month);

        self::assertSame(
            array_sum(array_map(static fn ($c): float => $c->quantity, $jours)),
            $mois[0]->quantity,
        );
        self::assertSame(3, $mois[0]->days);
    }

    public function testUneCaseSansJourNeDivisePasParZero(): void
    {
        self::assertSame(0.0, (new \Merisu\Inventory\Domain\SalesBucket('x', 10.0, 5.0, 0))->averagePerDay());
    }

    // ── Le classement des produits ──────────────────────────────────────────

    public function testLeClassementVaDuPlusVenduAuMoins(): void
    {
        $lignes = SalesBreakdown::byProduct([
            self::vente('2026-08-24', 'a', 5.0, 50.0),
            self::vente('2026-08-25', 'a', 5.0, 50.0),
            self::vente('2026-08-24', 'b', 40.0, 400.0),
        ]);

        self::assertSame(['b', 'a'], array_column($lignes, 'externalId'));
        self::assertSame(40.0, $lignes[0]['quantity']);
        self::assertSame(2, $lignes[1]['days']);
    }

    public function testOnPeutIsolerUnProduit(): void
    {
        $ventes = [
            self::vente('2026-08-24', 'a', 5.0),
            self::vente('2026-08-24', 'b', 40.0),
        ];

        $cases = SalesBreakdown::forProduct($ventes, 'a', SalesPeriod::Day);

        self::assertCount(1, $cases);
        self::assertSame(5.0, $cases[0]->quantity);
    }

    // ── La lecture du rapport de la caisse ──────────────────────────────────

    /**
     * L'horodatage arrive en MILLISECONDES, et la date est celle du COMPTOIR.
     *
     * Lu en secondes, il aurait daté les ventes de l'an 58000 et vidé chaque
     * intervalle sans erreur visible. Lu en UTC, une vente de 23 h à Varsovie
     * serait tombée la veille — et la journée du lundi aurait perdu sa
     * dernière heure au profit du dimanche.
     */
    public function testLHorodatageEstLuEnMillisecondesEtDansLeFuseauDeLaBoutique(): void
    {
        // 2026-08-23 22:30 UTC = 2026-08-24 00:30 à Varsovie.
        $ms = (new \DateTimeImmutable('2026-08-23 22:30:00', new \DateTimeZone('UTC')))->getTimestamp() * 1000;

        $ligne = [
            'group_by_value' => ['name' => (string) $ms],
            'aggregate' => ['sales' => ['product_quantity' => 12.0, 'total_money' => ['amount' => 340.5]]],
        ];

        $utc = PosSale::fromReport($ligne, '1', 'Traditional Regular', 'UTC');
        $varsovie = PosSale::fromReport($ligne, '1', 'Traditional Regular', 'Europe/Warsaw');

        self::assertNotNull($utc);
        self::assertNotNull($varsovie);
        self::assertSame('2026-08-23', $utc->date);
        self::assertSame('2026-08-24', $varsovie->date);
        self::assertSame(12.0, $varsovie->quantity);
        self::assertSame(340.5, $varsovie->revenue);
    }

    public function testUneLigneIllisibleEstEcartee(): void
    {
        self::assertNull(PosSale::fromReport([], '1', 'x', 'UTC'));
        self::assertNull(PosSale::fromReport(['group_by_value' => ['name' => 'hier']], '1', 'x', 'UTC'));
    }

    public function testUneQuantiteAbsenteVautZeroEtNonUneErreur(): void
    {
        $ms = (new \DateTimeImmutable('2026-08-24 10:00:00', new \DateTimeZone('UTC')))->getTimestamp() * 1000;
        $vente = PosSale::fromReport(['group_by_value' => ['name' => (string) $ms]], '1', 'x', 'UTC');

        self::assertNotNull($vente);
        self::assertSame(0.0, $vente->quantity);
        self::assertSame(0.0, $vente->revenue);
    }
}
