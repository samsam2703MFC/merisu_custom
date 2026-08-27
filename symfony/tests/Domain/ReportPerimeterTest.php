<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Adapter\Consultant;
use Merisu\Inventory\Adapter\Workstation;
use Merisu\Inventory\Domain\ReportPerimeter;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Domain\Shop;
use PHPUnit\Framework\TestCase;

/**
 * Le périmètre des rapports.
 *
 * C'est un contrôle d'accès : la boutique arrive par l'URL, et c'est ici qu'on
 * décide si elle est lisible. Les cas qui comptent sont ceux où quelqu'un
 * DEMANDE ce qu'il n'a pas.
 */
final class ReportPerimeterTest extends TestCase
{
    /** @return list<Shop> */
    private static function reseau(): array
    {
        return [
            new Shop('shop-1', 'WROCLAW', 'Wrocław Rynek'),
            new Shop('shop-2', 'WARSZAWA', 'Warszawa Nowy Świat'),
            new Shop('shop-3', 'KRAKOW', 'Kraków Floriańska'),
        ];
    }

    /** @param list<string> $shops */
    private static function perimetre(Role $role, array $shops = []): ReportPerimeter
    {
        return new ReportPerimeter(
            new Consultant('p-1', 'Gian', 'Marco', $role, 'ws-1', true, null, null, $shops),
            self::reseau(),
        );
    }

    /** @return list<Workstation> */
    private static function postes(): array
    {
        return [
            new Workstation('ws-1', 'Comptoir Wrocław', true, 'shop-1'),
            new Workstation('ws-2', 'Labo Wrocław', true, 'shop-1'),
            new Workstation('ws-3', 'Comptoir Varsovie', true, 'shop-2'),
            // Le poste d'avant le réseau : rattaché à rien.
            new Workstation('ws-0', 'Poste historique', true, ''),
        ];
    }

    // ── Ce qu'on peut lire ──────────────────────────────────────────────────

    public function testLAdministrateurLitToutLeReseau(): void
    {
        self::assertCount(3, self::perimetre(Role::Admin)->shops());
    }

    public function testLeManagerNeLitQueLesSiennes(): void
    {
        $p = self::perimetre(Role::Manager, ['shop-1', 'shop-3']);

        self::assertSame(
            ['Wrocław Rynek', 'Kraków Floriańska'],
            array_map(static fn (Shop $b): string => $b->name, $p->shops()),
        );
    }

    public function testUnManagerSansAffectationNeLitAucuneBoutique(): void
    {
        self::assertSame([], self::perimetre(Role::Manager, [])->shops());
    }

    public function testPersonneDeConnecteNeLitRien(): void
    {
        // Le repli d'une règle d'accès doit fermer, pas ouvrir.
        $p = new ReportPerimeter(null, self::reseau());

        self::assertSame([], $p->shops());
        self::assertNull($p->resolve('WROCLAW'));
        self::assertTrue($p->filtersSales(null));
        self::assertSame([], $p->codes(null));
        self::assertSame([], $p->workstationIds(null, self::postes()));
    }

    // ── LE cas : demander ce qu'on n'a pas ──────────────────────────────────

    /**
     * `?boutique=WARSZAWA` tapé par un manager de Wrocław.
     *
     * Sans ce contrôle, les chiffres de Varsovie s'affichaient — sans
     * effraction, dans la barre d'adresse.
     */
    public function testUneBoutiqueHorsPerimetreNEstPasServie(): void
    {
        $p = self::perimetre(Role::Manager, ['shop-1']);

        self::assertNull($p->resolve('WARSZAWA'));
        self::assertNull($p->resolve('shop-2'));
    }

    /**
     * Elle est RAMENÉE au périmètre, pas refusée : un lien partagé entre deux
     * managers doit montrer ce que celui qui l'ouvre a le droit de voir.
     */
    public function testHorsPerimetreOnRetombeSurSonPropreperimetre(): void
    {
        $p = self::perimetre(Role::Manager, ['shop-1']);

        self::assertSame(['WROCLAW'], $p->codes($p->resolve('WARSZAWA')));
    }

    public function testLaBoutiqueSeDesigneParSonCodeCommeParSonIdentifiant(): void
    {
        $p = self::perimetre(Role::Admin);

        self::assertSame('shop-2', $p->resolve('WARSZAWA')?->id);
        self::assertSame('shop-2', $p->resolve('shop-2')?->id);
    }

    // ── « Toutes » ne veut pas dire la même chose pour tout le monde ────────

    /**
     * Un administrateur sans choix ne filtre PAS : il doit voir aussi les
     * remontées d'avant le réseau, qui ne portent aucun code. Les taire ferait
     * un total inférieur à la somme des ventes, sans que rien ne l'explique.
     */
    public function testLAdministrateurSansChoixNeFiltrePas(): void
    {
        self::assertFalse(self::perimetre(Role::Admin)->filtersSales(null));
    }

    /** Un manager sans choix reste restreint : « toutes » = toutes LES SIENNES. */
    public function testLeManagerSansChoixResteRestreint(): void
    {
        $p = self::perimetre(Role::Manager, ['shop-1', 'shop-3']);

        self::assertTrue($p->filtersSales(null));
        self::assertSame(['WROCLAW', 'KRAKOW'], $p->codes(null));
    }

    /**
     * Le cas qui doit rendre AUCUNE vente, et non toutes.
     *
     * Un manager sans affectation avec une liste de codes vide : lue comme
     * « pas de filtre », elle aurait ouvert le réseau entier.
     */
    public function testLeManagerSansAffectationFiltreSurRien(): void
    {
        $p = self::perimetre(Role::Manager, []);

        self::assertTrue($p->filtersSales(null));
        self::assertSame([], $p->codes(null));
    }

    // ── Le pont vers les comptages : le poste ───────────────────────────────

    public function testLesPostesSuiventLaBoutiqueChoisie(): void
    {
        $p = self::perimetre(Role::Admin);

        self::assertSame(['ws-1', 'ws-2'], $p->workstationIds($p->resolve('WROCLAW'), self::postes()));
    }

    /**
     * Le poste SANS boutique : celui d'avant le réseau.
     *
     * L'administrateur le garde — le cacher ferait disparaître son historique.
     * Le manager ne l'a jamais : il n'a aucun moyen de dire à qui il
     * appartient, et le lui donner mêlerait à ses chiffres des comptages qui
     * ne sont peut-être pas les siens.
     */
    public function testLePosteSansBoutiqueResteALAdministrateurSeul(): void
    {
        self::assertContains('ws-0', self::perimetre(Role::Admin)->workstationIds(null, self::postes()));

        $manager = self::perimetre(Role::Manager, ['shop-1']);
        self::assertSame(['ws-1', 'ws-2'], $manager->workstationIds(null, self::postes()));
    }

    /** Une boutique choisie ne ramène JAMAIS le poste historique. */
    public function testUneBoutiqueChoisieNAttrapePasLePosteHistorique(): void
    {
        $p = self::perimetre(Role::Admin);

        self::assertNotContains('ws-0', $p->workstationIds($p->resolve('WROCLAW'), self::postes()));
    }

    public function testUnManagerSansAffectationNAAucunPoste(): void
    {
        self::assertSame([], self::perimetre(Role::Manager, [])->workstationIds(null, self::postes()));
    }
}
