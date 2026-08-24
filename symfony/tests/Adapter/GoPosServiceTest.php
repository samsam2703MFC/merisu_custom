<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Adapter;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Adapter\GoPosService;
use Merisu\Inventory\Adapter\PosUnavailable;
use Merisu\Inventory\Service\SecretBox;
use Merisu\Inventory\Store\PosCredentialStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * L'adaptateur de caisse, SANS réseau.
 *
 * Les réponses écrites ici ne sont pas inventées : elles ont été RELEVÉES sur
 * `app.gopos.io` en interrogeant `/oauth/token` avec des identifiants factices.
 * C'est ce qui permet d'affirmer, plus bas, que la caisse juge la paire client
 * avant de regarder quoi que ce soit d'autre.
 */
final class GoPosServiceTest extends TestCase
{
    /** Mot pour mot ce que rend `app.gopos.io` à un client inconnu. */
    private const REFUS_CLIENT = '{"error":"invalid_client","error_description":"Bad client credentials"}';

    private function magasinVide(): PosCredentialStore
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);

        return new PosCredentialStore($db, new SecretBox('secret-de-test'));
    }

    /**
     * Une fabrique de réponses, et non une réponse.
     *
     * `token()` peut faire DEUX appels — corps de formulaire, puis chaîne de
     * requête. Une `MockResponse` unique ne sert qu'une fois : au deuxième
     * appel, le client de test lève « No more response left in queue », que
     * l'adaptateur prend pour une panne réseau. Le refus étudié ici
     * n'arrivait alors jamais jusqu'à l'assertion.
     *
     * @return \Closure(): MockResponse
     */
    private static function repond(int $statut, string $corps): \Closure
    {
        return static fn (): MockResponse => new MockResponse($corps, ['http_code' => $statut]);
    }

    private function service(callable|MockResponse $reponse): GoPosService
    {
        return new GoPosService(
            $this->magasinVide(),
            'client-essai',
            'secret-essai',
            'organisation-essai',
            'https://exemple.test',
            new MockHttpClient($reponse),
        );
    }

    /**
     * LE point de ce fichier.
     *
     * GoPOS valide le client AVANT tout le reste : `organization_id` juste,
     * faux ou absent, `grant_type` attendu ou fantaisiste, la réponse ne change
     * pas. Quand elle dit `invalid_client`, elle n'a donc pas encore regardé
     * l'Organization ID — et le message d'écran ne doit pas y envoyer.
     */
    public function testUnClientInconnuNommeLaPaireEnCauseEtElleSeule(): void
    {
        $service = $this->service(self::repond(401, self::REFUS_CLIENT));

        try {
            $service->ping();
            self::fail('le refus aurait dû être signalé');
        } catch (PosUnavailable $e) {
            self::assertSame('admin.pos.badClient', $e->getMessage());
            self::assertStringContainsString('invalid_client', $e->detail);
            self::assertStringContainsString('Bad client credentials', $e->detail);
        }
    }

    /**
     * Tout autre refus garde le message général.
     *
     * Nommer la paire client sur une panne serveur aurait envoyé quelqu'un
     * régénérer des identifiants parfaitement bons.
     */
    #[DataProvider('autresRefus')]
    public function testLesAutresRefusGardentLeMessageGeneral(int $statut, string $corps): void
    {
        $service = $this->service(self::repond($statut, $corps));

        try {
            $service->ping();
            self::fail('le refus aurait dû être signalé');
        } catch (PosUnavailable $e) {
            self::assertSame('admin.pos.tokenRefused', $e->getMessage());
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function autresRefus(): iterable
    {
        // Relevé lui aussi : ce que rend la caisse quand le corps n'est pas un
        // formulaire, et qu'elle ne lit donc aucun identifiant.
        yield 'requête non lue' => [401, '{"timestamp":1787589556899,"status":401,"error":"Unauthorized","path":"/oauth/token"}'];
        yield 'panne de la caisse' => [500, '{"error":"server_error"}'];
        yield 'page HTML' => [503, '<html><body>maintenance</body></html>'];
    }

    /**
     * Le jeton obtenu, puis un refus : ce n'est plus la paire client.
     *
     * Confondre les deux envoyait revérifier un secret qui venait de servir.
     */
    public function testUnRefusAPRESLeJetonParleDeLOrganisation(): void
    {
        $appels = 0;

        $service = $this->service(function () use (&$appels): MockResponse {
            ++$appels;

            return $appels === 1
                ? new MockResponse('{"access_token":"jeton-valide","expires_in":3600}')
                : new MockResponse('{"message":"Forbidden"}', ['http_code' => 403]);
        });

        try {
            $service->ping();
            self::fail('le refus aurait dû être signalé');
        } catch (PosUnavailable $e) {
            self::assertSame('admin.pos.accessRefused', $e->getMessage());
        }
    }

    /** Le secret ne figure jamais dans ce qui remonte à l'écran. */
    public function testLeSecretNApparaitPasDansLeRefus(): void
    {
        $service = $this->service(self::repond(401, self::REFUS_CLIENT));

        try {
            $service->ping();
            self::fail('le refus aurait dû être signalé');
        } catch (PosUnavailable $e) {
            self::assertStringNotContainsString('secret-essai', $e->detail);
            self::assertStringNotContainsString('secret-essai', $e->getMessage());
        }
    }
}
