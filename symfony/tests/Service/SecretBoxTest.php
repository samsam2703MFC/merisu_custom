<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Service;

use Merisu\Inventory\Service\SecretBox;
use PHPUnit\Framework\TestCase;

/**
 * Le chiffrement des secrets qu'il faut pouvoir RELIRE.
 *
 * Distinct du hachage des codes PIN, et strictement moins sûr : un code se
 * vérifie, un secret de caisse doit être présenté tel quel à GoPOS. Ce qui se
 * vérifie ici, c'est que la faiblesse s'arrête là — la clé n'est pas dans la
 * base, et une valeur illisible ne devient jamais du charabia envoyé au
 * réseau.
 */
final class SecretBoxTest extends TestCase
{
    private const SECRET = 'secret-application-de-test-0123456789';

    public function testUnSecretSeRelitApresChiffrement(): void
    {
        $coffre = new SecretBox(self::SECRET);
        $chiffre = $coffre->encrypt('sk-gopos-4f2a');

        self::assertNotNull($chiffre);
        self::assertStringNotContainsString('sk-gopos-4f2a', $chiffre);
        self::assertSame('sk-gopos-4f2a', $coffre->decrypt($chiffre));
    }

    /**
     * Un nonce neuf à chaque fois : deux sauvegardes du même secret ne se
     * ressemblent pas, et l'on ne peut donc pas deviner qu'une valeur n'a pas
     * changé en les comparant.
     */
    public function testDeuxChiffrementsDuMemeSecretDiffèrent(): void
    {
        $coffre = new SecretBox(self::SECRET);

        self::assertNotSame($coffre->encrypt('même-secret'), $coffre->encrypt('même-secret'));
    }

    /**
     * LA garantie qui compte : changer APP_SECRET rend les secrets illisibles.
     * C'est voulu — une rotation de secret d'application DOIT invalider ce
     * qu'il protégeait — et cela se voit, au lieu de produire des octets au
     * hasard qu'on aurait envoyés à la caisse.
     */
    public function testUneAutreCleNeDechiffreRien(): void
    {
        $chiffre = (new SecretBox(self::SECRET))->encrypt('sk-gopos-4f2a');

        self::assertNotNull($chiffre);
        self::assertNull((new SecretBox('une-tout-autre-cle-application'))->decrypt($chiffre));
    }

    public function testUneValeurAbimeeNeDechiffreRien(): void
    {
        $coffre = new SecretBox(self::SECRET);

        self::assertNull($coffre->decrypt(null));
        self::assertNull($coffre->decrypt(''));
        self::assertNull($coffre->decrypt('pas-du-tout-chiffre'));
        self::assertNull($coffre->decrypt('sb1:pas-du-base64-valide!!'));
        self::assertNull($coffre->decrypt('sb1:' . base64_encode('trop court')));
    }

    /** Sans APP_SECRET, on ne chiffre pas — et l'appelant refuse d'écrire. */
    public function testSansSecretDApplicationRienNEstChiffre(): void
    {
        $coffre = new SecretBox('   ');

        self::assertFalse($coffre->isAvailable());
        self::assertNull($coffre->encrypt('sk-gopos-4f2a'));
    }

    public function testUneChaineVideNeSeChiffrePas(): void
    {
        self::assertNull((new SecretBox(self::SECRET))->encrypt(''));
    }
}
