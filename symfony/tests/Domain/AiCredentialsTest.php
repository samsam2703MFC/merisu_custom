<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\AiCredentials;
use PHPUnit\Framework\TestCase;

final class AiCredentialsTest extends TestCase
{
    public function testUneFicheEstCompleteDesQueLaCleEstLa(): void
    {
        self::assertTrue((new AiCredentials('cle'))->isComplete());
        self::assertFalse((new AiCredentials(''))->isComplete());
        self::assertFalse((new AiCredentials('   '))->isComplete());
    }

    public function testLeModeleAToujoursUnDefaut(): void
    {
        self::assertSame(AiCredentials::DEFAULT_MODEL, (new AiCredentials('cle'))->model);
        self::assertSame('claude-sonnet-5', (new AiCredentials('cle', 'claude-sonnet-5'))->model);
    }

    /**
     * Un champ modèle laissé vide ne pose pas une chaîne vide comme modèle :
     * il retombe sur le défaut, faute de quoi l'appel partirait sans modèle.
     */
    public function testLeModeleVideRetombeSurLeDefaut(): void
    {
        self::assertSame(AiCredentials::DEFAULT_MODEL, AiCredentials::cleanModel(''));
        self::assertSame(AiCredentials::DEFAULT_MODEL, AiCredentials::cleanModel('   '));
        self::assertSame(AiCredentials::DEFAULT_MODEL, AiCredentials::cleanModel(null));
        self::assertSame('claude-opus-5', AiCredentials::cleanModel('  claude-opus-5  '));
    }

    /**
     * `fromScreen` distingue une fiche saisie d'une valeur d'environnement :
     * l'écran l'emporte, mais seulement s'il porte réellement une clé.
     */
    public function testProvenanceEcran(): void
    {
        self::assertFalse((new AiCredentials('cle'))->fromScreen);
        self::assertTrue((new AiCredentials('cle', AiCredentials::DEFAULT_MODEL, true))->fromScreen);
    }
}
