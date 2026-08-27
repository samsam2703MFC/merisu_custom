<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Adapter\Consultant;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Domain\Shop;
use PHPUnit\Framework\TestCase;

/**
 * Le périmètre d'un manager : ce qu'il pilote, et surtout ce qu'il ne pilote
 * pas.
 */
final class ManagerScopeTest extends TestCase
{
    /** @return list<Shop> */
    private static function boutiques(): array
    {
        return [
            new Shop('shop-1', 'WROCLAW', 'Wrocław Rynek'),
            new Shop('shop-2', 'WARSZAWA', 'Warszawa Nowy Świat'),
            new Shop('shop-3', 'KRAKOW', 'Kraków Floriańska'),
        ];
    }

    /** @param list<string> $shops */
    private static function personne(Role $role, array $shops = []): Consultant
    {
        return new Consultant(
            'p-1',
            'Gian',
            'Marco',
            $role,
            'ws-1',
            true,
            'g@m.example',
            null,
            $shops,
        );
    }

    /**
     * `isAdmin()` reste STRICT.
     *
     * Treize contrôles s'appuient dessus et quinze contrôleurs sur
     * `requireAdmin()` : y faire entrer le manager aurait ouvert le réseau
     * entier en silence.
     */
    public function testUnManagerNEstPasUnAdministrateur(): void
    {
        self::assertFalse(Role::Manager->isAdmin());
        self::assertTrue(Role::Manager->canManage());

        self::assertTrue(Role::Admin->isAdmin());
        self::assertTrue(Role::Admin->canManage());

        self::assertFalse(Role::Consultant->isAdmin());
        self::assertFalse(Role::Consultant->canManage());
    }

    public function testLAdministrateurPiloteToutesLesBoutiques(): void
    {
        $admin = self::personne(Role::Admin);

        foreach (self::boutiques() as $boutique) {
            self::assertTrue($admin->managesShop($boutique->id, self::boutiques()));
        }
    }

    public function testUnManagerNePiloteQueLesSiennes(): void
    {
        $manager = self::personne(Role::Manager, ['shop-1']);

        self::assertTrue($manager->managesShop('shop-1', self::boutiques()));
        self::assertFalse($manager->managesShop('shop-2', self::boutiques()));
        self::assertFalse($manager->managesShop('shop-3', self::boutiques()));
    }

    /**
     * LE point qui compte. Une liste vide veut dire « on ne lui en a pas encore
     * donné », pas « le réseau entier » — sans quoi promouvoir quelqu'un
     * manager avant d'avoir rempli sa fiche lui ouvrirait toutes les boutiques.
     */
    public function testUnManagerSansAffectationNePiloteRIEN(): void
    {
        $manager = self::personne(Role::Manager, []);

        foreach (self::boutiques() as $boutique) {
            self::assertFalse($manager->managesShop($boutique->id, self::boutiques()));
        }

        self::assertSame([], $manager->shopIds(self::boutiques()));
    }

    /**
     * Le champ a longtemps porté du TEXTE LIBRE. Une affectation posée il y a
     * six mois doit rester lisible plutôt que de disparaître en silence.
     */
    public function testLAncienTexteLibreEstEncoreCompris(): void
    {
        $parNom = self::personne(Role::Manager, ['Wrocław Rynek']);

        self::assertSame(['shop-1'], $parNom->shopIds(self::boutiques()));
        self::assertTrue($parNom->managesShop('shop-1', self::boutiques()));
    }

    /** C'est ainsi que le champ libre a divergé : casse et espaces. */
    public function testLeRapprochementIgnoreLaCasseEtLesEspaces(): void
    {
        $flou = self::personne(Role::Manager, ['  wrocław rynek  ']);

        self::assertSame(['shop-1'], $flou->shopIds(self::boutiques()));
    }

    public function testUneBoutiqueDisparueNeLaisseRienDerriere(): void
    {
        $ancien = self::personne(Role::Manager, ['shop-9', 'Boutique fermée', 'shop-2']);

        self::assertSame(['shop-2'], $ancien->shopIds(self::boutiques()));
        self::assertFalse($ancien->managesShop('shop-9', self::boutiques()));
    }

    public function testUneBoutiqueCiteeDeuxFoisNeCompteQuUneFois(): void
    {
        $doublon = self::personne(Role::Manager, ['shop-1', 'Wrocław Rynek']);

        self::assertSame(['shop-1'], $doublon->shopIds(self::boutiques()));
    }

    /** Un consultant ne pilote rien, quoi qu'on lui ait affecté. */
    public function testUnConsultantNePiloteRienMemeAffecte(): void
    {
        $vendeur = self::personne(Role::Consultant, ['shop-1', 'shop-2']);

        self::assertFalse($vendeur->role->canManage());
        // Ses boutiques restent lisibles — elles disent où il travaille — mais
        // elles ne lui donnent aucun pouvoir de pilotage.
        self::assertSame(['shop-1', 'shop-2'], $vendeur->shopIds(self::boutiques()));
    }

    public function testLOrdreDesRolesVaDuMoinsAuPlusPuissant(): void
    {
        self::assertSame([Role::Consultant, Role::Manager, Role::Admin], Role::all());
    }
}
