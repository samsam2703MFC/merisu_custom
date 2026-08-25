<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\WeatherCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeatherCredentialsTest extends TestCase
{
    #[DataProvider('points')]
    public function testCeQuiFaitDesCoordonneesUtilisables(float $lat, float $lon, bool $attendu): void
    {
        self::assertSame($attendu, (new WeatherCredentials('k', $lat, $lon))->hasCoordinates());
    }

    /** @return iterable<string, array{float, float, bool}> */
    public static function points(): iterable
    {
        yield 'Varsovie' => [52.2297, 21.0122, true];
        yield 'Palerme' => [38.1157, 13.3615, true];
        yield 'pôle nord' => [90.0, 0.0, true];
        yield 'antiméridien' => [0.0, 180.0, true];
        // Le point (0, 0) tombe dans le golfe de Guinée, et c'est exactement ce
        // que rend un formulaire dont les deux champs sont restés vides.
        // L'accepter aurait donné une prévision marine présentée comme celle
        // de la boutique.
        yield 'deux champs vides' => [0.0, 0.0, false];
        yield 'latitude hors du globe' => [91.0, 21.0, false];
        yield 'longitude hors du globe' => [52.0, 181.0, false];
    }

    public function testUneFicheEstCompleteQuandLaCleEtLEndroitSontLa(): void
    {
        self::assertTrue((new WeatherCredentials('cle', 52.2, 21.0))->isComplete());
        self::assertFalse((new WeatherCredentials('', 52.2, 21.0))->isComplete());
        self::assertFalse((new WeatherCredentials('   ', 52.2, 21.0))->isComplete());
        self::assertFalse((new WeatherCredentials('cle', 0.0, 0.0))->isComplete());
    }

    /**
     * La clé ne sort JAMAIS vers l'écran, même tronquée.
     *
     * « …4f2a » suffit à confirmer à qui l'a volée qu'elle tient la bonne.
     */
    public function testLaCleNeFigurePasDansCeQueLEcranMontre(): void
    {
        $vue = (new WeatherCredentials('cle-secrete-42', 52.2297, 21.0122, 'Varsovie', true))->display();

        self::assertSame(
            ['latitude', 'longitude', 'place', 'autoApply', 'apiVersion', 'hasKey'],
            array_keys($vue),
        );
        self::assertTrue($vue['hasKey']);
        self::assertTrue($vue['autoApply']);
        self::assertSame('Varsovie', $vue['place']);
        self::assertStringNotContainsString('cle-secrete-42', json_encode($vue, \JSON_THROW_ON_ERROR));
    }

    public function testSansCleLEcranLeDit(): void
    {
        self::assertFalse((new WeatherCredentials('', 52.2, 21.0))->display()['hasKey']);
    }

    /**
     * L'application automatique est DÉCOCHÉE par défaut.
     *
     * La semaine type est une saisie : quelqu'un a regardé et décidé.
     * L'écraser d'office aurait défait ce choix sans que personne l'ait
     * demandé.
     */
    public function testLApplicationAutomatiqueEstFausseParDefaut(): void
    {
        self::assertFalse((new WeatherCredentials('cle', 52.2, 21.0))->autoApply);
    }
}
