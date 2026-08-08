<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Security;

use Merisu\Inventory\Security\PinField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * La lecture du code, partagée par la connexion, la check-list et le plan de
 * production.
 *
 * Trois écrans posent la même question ; ils doivent la lire de la même façon.
 * Une divergence ici signifierait qu'un code valide est accepté d'un côté et
 * refusé de l'autre — et la personne concernée n'aurait aucun moyen de le
 * comprendre.
 */
final class PinFieldTest extends TestCase
{
    private static function requete(mixed $secret): Request
    {
        return new Request([], $secret === null ? [] : ['secret' => $secret]);
    }

    public function testLesSixCoupesSeRecollent(): void
    {
        self::assertSame('654321', PinField::read(self::requete(['6', '5', '4', '3', '2', '1'])));
    }

    /**
     * Un champ unique reste accepté : un client qui poste une chaîne — outil
     * de test, navigateur exotique — ne doit pas se voir refuser un code
     * pourtant correct.
     */
    public function testUnChampUniqueResteAccepte(): void
    {
        self::assertSame('654321', PinField::read(self::requete('654321')));
    }

    public function testLesEspacesDeSaisieSontRetires(): void
    {
        self::assertSame('654321', PinField::read(self::requete([' 6', '5 ', ' 4 ', '3', '2', '1'])));
        self::assertSame('654321', PinField::read(self::requete("  654321\n")));
    }

    /** @return iterable<string, array{mixed}> */
    public static function envoisAberrants(): iterable
    {
        yield 'aucun champ' => [null];
        yield 'chaîne vide' => [''];
        yield 'tableau vide' => [[]];
        yield 'coupe imbriquée' => [[['6'], '5']];
        yield 'valeur imbriquée' => [['secret' => ['6']]];
    }

    /**
     * Rien ne doit être fatal ici. Un écran de connexion qui tombe en 500 sur
     * une requête malformée renseigne déjà celui qui la forge — et la suite
     * du contrôleur refusera de toute façon un code qui n'en est pas un.
     *
     * @param mixed $secret
     */
    #[DataProvider('envoisAberrants')]
    public function testUnEnvoiAberrantNeCasseRien(mixed $secret): void
    {
        $lu = PinField::read(self::requete($secret));

        self::assertIsString($lu);
        self::assertNotSame('654321', $lu);
    }

    /** Le nom du champ est CELUI que les gabarits emploient. */
    public function testLeNomDuChampEstPartage(): void
    {
        self::assertSame('secret', PinField::NAME);
    }
}
