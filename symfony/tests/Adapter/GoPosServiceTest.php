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
            '13232',
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

    /**
     * Chaque refus relevé sur la caisse mène au geste qui le répare.
     *
     * Les quatre corps ci-dessous sont ceux d'`app.gopos.io`, mot pour mot.
     * Un message unique les aurait tous renvoyés vérifier trois valeurs, alors
     * que la caisse en met une seule en cause à chaque fois.
     */
    #[DataProvider('refusReleves')]
    public function testChaqueRefusMeneAuBonGeste(string $corps, string $attendu): void
    {
        $service = $this->service(self::repond(400, $corps));

        try {
            $service->ping();
            self::fail('le refus aurait dû être signalé');
        } catch (PosUnavailable $e) {
            self::assertSame($attendu, $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusReleves(): iterable
    {
        yield 'paire client inconnue' => [
            self::REFUS_CLIENT,
            'admin.pos.badClient',
        ];
        // Le refus que la boutique a réellement reçu : identifiants bons,
        // mais pas pour cette organisation.
        yield 'paire bonne, boutique non accordée' => [
            '{"error":"invalid_grant","error_description":"client has no ACTIVE grant for given organization_id"}',
            'admin.pos.noGrant',
        ];
        // Ce que rend « go_13410 », le numéro tel que le panneau l'affiche.
        yield 'préfixe go_ laissé devant le numéro' => [
            '{"error":"invalid_request","error_description":"organization_id has invalid format"}',
            'admin.pos.badOrganizationFormat',
        ];
        yield 'numéro absent' => [
            '{"error":"invalid_request","error_description":"organization_id parameter must be given"}',
            'admin.pos.noOrganization',
        ];
    }

    // ── La pagination ───────────────────────────────────────────────────────

    /**
     * La première page porte le numéro ZÉRO.
     *
     * Commencer à un demandait la DEUXIÈME page dès le premier appel. Sur un
     * catalogue de cinquante articles lus par pages de cent, la caisse
     * répondait « aucun article » sans la moindre erreur — HTTP 200, liste
     * vide — et l'import n'entrait rien en croyant n'avoir rien à entrer.
     */
    public function testLaPremiereePageDemandeeEstLaZero(): void
    {
        $pages = [];

        $service = $this->service(function (string $methode, string $url) use (&$pages): MockResponse {
            if (str_contains($url, '/oauth/token')) {
                return new MockResponse('{"access_token":"jeton","expires_in":899}');
            }

            parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);
            $pages[] = $q['page'] ?? '(aucune)';

            // Une seule page, plus courte que la taille demandée : la boucle
            // doit s'arrêter là.
            return new MockResponse(json_encode([
                'data' => [['id' => 12, 'name' => 'Amaretto', 'status' => 'ENABLED']],
            ], \JSON_THROW_ON_ERROR));
        });

        $categories = $service->categories();

        self::assertSame(['0'], $pages);
        self::assertCount(1, $categories);
        self::assertSame('Amaretto', $categories[0]->name);
    }

    /**
     * Un catalogue plus long qu'une page est lu jusqu'au bout.
     *
     * La caisse n'annonce aucun total : une page pleine est le seul indice
     * qu'il en reste une autre.
     */
    public function testUnCataloguePleinEstLuJusquAuBout(): void
    {
        $pages = [];

        $service = $this->service(function (string $methode, string $url) use (&$pages): MockResponse {
            if (str_contains($url, '/oauth/token')) {
                return new MockResponse('{"access_token":"jeton","expires_in":899}');
            }

            parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);
            $page = (int) ($q['page'] ?? 0);
            $pages[] = $page;

            // Page 0 pleine (cent lignes), page 1 partielle : deux appels.
            $lignes = [];
            $combien = $page === 0 ? 100 : 3;

            for ($i = 0; $i < $combien; ++$i) {
                $lignes[] = ['id' => $page * 100 + $i, 'name' => 'Catégorie ' . $i, 'status' => 'ENABLED'];
            }

            return new MockResponse(json_encode(['data' => $lignes], \JSON_THROW_ON_ERROR));
        });

        $categories = $service->categories();

        self::assertSame([0, 1], $pages);
        self::assertCount(103, $categories);
    }

    // ── Le nom de la boutique ───────────────────────────────────────────────

    /**
     * `ping()` interroge `/me`, seul endroit qui porte un NOM.
     *
     * `/settings` rend une liste de réglages de caisse — `SALE_QUICK_BUTTON`
     * et consorts — où aucun champ `name` n'existe. L'écran retombait donc sur
     * le numéro : « Organisation : 13232 », alors que la question posée est
     * justement QUELLE boutique répond.
     */
    public function testLeNomDeLaBoutiqueVientDeMe(): void
    {
        $vus = [];

        $service = $this->service(function (string $methode, string $url) use (&$vus): MockResponse {
            if (str_contains($url, '/oauth/token')) {
                return new MockResponse('{"access_token":"jeton","expires_in":899}');
            }

            $vus[] = (string) parse_url($url, \PHP_URL_PATH);

            return new MockResponse('{"data":[{"id":13232,"name":"Merisù","status":"ENABLED"}]}');
        });

        self::assertSame('Merisù', $service->ping());
        // Hors du chemin de l'organisation : `/me` vit à la racine.
        self::assertSame(['/api/v3/me'], $vus);
    }

    /**
     * Plusieurs organisations : c'est CELLE QU'ON A DEMANDÉE qui est nommée.
     *
     * Rendre la première venue aurait affiché le nom d'une boutique voisine,
     * et l'écran aurait confirmé une connexion vers la mauvaise adresse.
     */
    public function testParmiPlusieursCEstLOrganisationDemandeeQuiEstNommee(): void
    {
        $service = $this->service(function (string $methode, string $url): MockResponse {
            return str_contains($url, '/oauth/token')
                ? new MockResponse('{"access_token":"jeton","expires_in":899}')
                : new MockResponse('{"data":[{"id":9999,"name":"Boutique voisine"},{"id":13232,"name":"Merisù"}]}');
        });

        self::assertSame('Merisù', $service->ping());
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
