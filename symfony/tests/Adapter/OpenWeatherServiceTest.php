<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Adapter;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Adapter\OpenWeatherService;
use Merisu\Inventory\Adapter\WeatherUnavailable;
use Merisu\Inventory\Domain\WeatherCredentials;
use Merisu\Inventory\Domain\WeatherKind;
use Merisu\Inventory\Service\SecretBox;
use Merisu\Inventory\Store\WeatherCredentialStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * L'adaptateur météo, SANS réseau.
 *
 * Le client HTTP est remplacé par un mannequin : c'est le seul moyen de
 * vérifier ce qui compte — la requête envoyée, la lecture de ce qui revient,
 * et surtout ce qui remonte à l'écran en cas de panne — sans dépendre d'un
 * service facturé et d'une réponse qui change toutes les heures.
 */
final class OpenWeatherServiceTest extends TestCase
{
    private const CLE = 'cle-tres-secrete-42';

    /** Un magasin d'identifiants qui ne trouve jamais de saisie d'écran. */
    private function magasinVide(): WeatherCredentialStore
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);

        return new WeatherCredentialStore($db, new SecretBox('secret-de-test'));
    }

    private function service(callable|MockResponse $reponse, string $cle = self::CLE): OpenWeatherService
    {
        return new OpenWeatherService(
            $this->magasinVide(),
            $cle,
            '52.2297',
            '21.0122',
            'Varsovie',
            'https://exemple.test',
            new MockHttpClient($reponse),
        );
    }

    /** @param list<array{int, int}> $jours [décalage en jours, code météo] */
    private static function reponseHote(array $jours, int $offset = 7200): MockResponse
    {
        $minuit = strtotime('2026-08-03 00:00:00 UTC') - $offset;
        $daily = [];

        foreach ($jours as [$decalage, $code]) {
            $daily[] = [
                'dt' => $minuit + $decalage * 86400 + 43200,
                'summary' => 'Essai',
                'temp' => ['min' => 12.0, 'max' => 24.0],
                'weather' => [['id' => $code]],
                'pop' => 0.4,
            ];
        }

        return new MockResponse(json_encode([
            'timezone' => 'Europe/Warsaw',
            'timezone_offset' => $offset,
            'daily' => $daily,
        ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    // ── La requête ──────────────────────────────────────────────────────────

    /**
     * Ce qui part, et rien de plus.
     *
     * `exclude` n'est pas cosmétique : sans lui la réponse porte la minute par
     * minute sur une heure et l'heure par heure sur deux jours — plusieurs
     * centaines de lignes dont ce module ne fait rien.
     */
    public function testLaRequeteVaAuBonEndroitAvecLesBonsParametres(): void
    {
        $vues = [];

        $service = $this->service(function (string $methode, string $url) use (&$vues): MockResponse {
            $vues[] = [$methode, $url];

            return self::reponseHote([[0, 800]]);
        });

        $service->forecast('2026-08-03', 'fr');

        self::assertCount(1, $vues);
        self::assertSame('GET', $vues[0][0]);
        self::assertStringStartsWith('https://exemple.test/data/3.0/onecall?', $vues[0][1]);

        parse_str((string) parse_url($vues[0][1], \PHP_URL_QUERY), $q);

        self::assertSame('52.2297', $q['lat']);
        self::assertSame('21.0122', $q['lon']);
        self::assertSame(self::CLE, $q['appid']);
        self::assertSame('metric', $q['units']);
        self::assertSame('current,minutely,hourly,alerts', $q['exclude']);
        self::assertSame('fr', $q['lang']);
    }

    /** Une langue que l'hôte ne parle pas : on demande l'anglais franchement. */
    #[DataProvider('langues')]
    public function testLaLangueEstRameneeACelleQueLHoteParle(string $demandee, string $envoyee): void
    {
        $url = '';

        $service = $this->service(function (string $m, string $u) use (&$url): MockResponse {
            $url = $u;

            return self::reponseHote([[0, 800]]);
        });

        $service->forecast('2026-08-03', $demandee);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);

        self::assertSame($envoyee, $q['lang']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function langues(): iterable
    {
        yield 'français' => ['fr', 'fr'];
        yield 'polonais' => ['pl', 'pl'];
        yield 'italien' => ['it', 'it'];
        yield 'espagnol' => ['es', 'es'];
        yield 'anglais' => ['en', 'en'];
        yield 'inconnue de la maison' => ['de', 'en'];
        yield 'vide' => ['', 'en'];
    }

    public function testSansCleAucunAppelNePart(): void
    {
        $service = $this->service(
            static fn (): MockResponse => throw new \LogicException("un appel est parti sans clé"),
            cle: '',
        );

        self::assertFalse($service->isConfigured());

        $this->expectException(WeatherUnavailable::class);
        $this->expectExceptionMessage('admin.weather.notConfigured');
        $service->forecast('2026-08-03');
    }

    // ── La réponse ──────────────────────────────────────────────────────────

    public function testLaSemaineSeLitEtSeRangeParJour(): void
    {
        $service = $this->service(self::reponseHote([
            [0, 800], [1, 500], [2, 804], [3, 601],
        ]));

        $prevision = $service->forecast('2026-08-03', 'fr');

        self::assertCount(4, $prevision->days);
        self::assertSame('Europe/Warsaw', $prevision->timezone);
        self::assertSame([
            'MON' => WeatherKind::Sunny,
            'TUE' => WeatherKind::Rain,
            'WED' => WeatherKind::Cloudy,
            'THU' => WeatherKind::Snow,
        ], $prevision->byDayOfWeek());
    }

    /**
     * Une réponse acceptée mais vide est traitée comme un refus.
     *
     * La laisser passer aurait effacé en base une prévision valable, et
     * remplacé une semaine connue par une semaine vide sans rien dire.
     */
    public function testUneReponseSansJourneeExploitableEstRefusee(): void
    {
        $service = $this->service(new MockResponse('{"daily":[]}'));

        $this->expectException(WeatherUnavailable::class);
        $this->expectExceptionMessage('admin.weather.badAnswer');
        $service->forecast('2026-08-03');
    }

    public function testUneReponseQuiNEstPasDuJsonEstRefusee(): void
    {
        $service = $this->service(new MockResponse('<html>maintenance</html>'));

        $this->expectException(WeatherUnavailable::class);
        $this->expectExceptionMessage('admin.weather.badAnswer');
        $service->forecast('2026-08-03');
    }

    // ── Les refus ───────────────────────────────────────────────────────────

    /**
     * Trois codes, trois messages — parce que trois gestes différents les
     * réparent : renouveler l'abonnement, attendre demain, corriger le point.
     */
    #[DataProvider('refus')]
    public function testChaqueRefusRenvoieVersLeBonGeste(int $statut, string $cle): void
    {
        $service = $this->service(new MockResponse('{"cod":' . $statut . ',"message":"non"}', [
            'http_code' => $statut,
        ]));

        $this->expectException(WeatherUnavailable::class);
        $this->expectExceptionMessage($cle);
        $service->forecast('2026-08-03');
    }

    /** @return iterable<string, array{int, string}> */
    public static function refus(): iterable
    {
        yield 'clé refusée' => [401, 'admin.weather.badKey'];
        yield 'accès interdit' => [403, 'admin.weather.badKey'];
        yield 'quota dépassé' => [429, 'admin.weather.quota'];
        yield 'requête mal formée' => [400, 'admin.weather.badPlace'];
        yield 'endroit introuvable' => [404, 'admin.weather.badPlace'];
        yield 'panne chez lhôte' => [500, 'admin.weather.hostRefused'];
    }

    /**
     * Le mot de l'hôte remonte tel quel.
     *
     * One Call 3.0 se souscrit séparément : une clé parfaitement valable
     * ailleurs répond ici « requires a separate subscription ». Sans cette
     * phrase à l'écran, on renvoie quelqu'un vérifier une clé qui n'a rien de
     * faux.
     */
    public function testLeMotDeLHoteRemonteAvecLeRefus(): void
    {
        $service = $this->service(new MockResponse(
            '{"cod":401,"message":"Please note that using One Call 3.0 requires a separate subscription."}',
            ['http_code' => 401],
        ));

        try {
            $service->forecast('2026-08-03');
            self::fail('le refus aurait dû être signalé');
        } catch (WeatherUnavailable $e) {
            self::assertStringContainsString('HTTP 401', $e->detail);
            self::assertStringContainsString('separate subscription', $e->detail);
        }
    }

    // ── One Call 4.0 ────────────────────────────────────────────────────────

    /**
     * La 4.0 nomme son tableau `data` là où la 3.0 dit `daily`.
     *
     * C'est la SEULE différence de forme : les journées portent les mêmes
     * champs — `dt`, `temp.min`, `temp.max`, `weather[].id`, `pop` — et le
     * décalage horaire est au même endroit. L'exemple ci-dessous est celui de
     * la documentation d'OpenWeather, réduit à ce que ce module lit.
     */
    public function testLaReponseDeLaVersion4SeLitCommeCelleDeLa3(): void
    {
        $minuit = strtotime('2026-08-03 00:00:00 UTC') - 7200;

        $payload = [
            'lat' => 51.5,
            'lon' => -0.1,
            'timezone' => 'Europe/London',
            'timezone_offset' => 7200,
            'data' => [[
                'dt' => $minuit + 43200,
                'temp' => ['day' => 18.1, 'min' => 12.7, 'max' => 20.05, 'night' => 15.7],
                'feels_like' => ['day' => 17.9],
                'pressure' => 1024,
                'humidity' => 45,
                'weather' => [['id' => 800, 'main' => 'Clear', 'description' => 'sky is clear', 'icon' => '01d']],
                'clouds' => 0,
                'pop' => 0.3,
                'uvi' => 4.82,
            ]],
            'prev' => 'https://api.openweathermap.org/data/4.0/onecall/timeline/1day?cnt=10',
            'next' => 'https://api.openweathermap.org/data/4.0/onecall/timeline/1day?cnt=10',
        ];

        $prevision = \Merisu\Inventory\Domain\WeatherForecast::fromHost($payload, '2026-08-03');

        self::assertCount(1, $prevision->days);
        self::assertSame('2026-08-03', $prevision->days[0]->date);
        self::assertSame(WeatherKind::Sunny, $prevision->days[0]->kind);
        self::assertSame(12.7, $prevision->days[0]->tempMin);
        self::assertSame(20.05, $prevision->days[0]->tempMax);
        self::assertSame(30, $prevision->days[0]->rainChance);
        self::assertSame('Europe/London', $prevision->timezone);
    }

    /**
     * Chaque version appelle SON chemin, avec SES paramètres.
     *
     * `exclude` n'existe pas en 4.0 — la série journalière se demande par
     * `cnt`. L'envoyer quand même n'aurait rien cassé, mais viser
     * `/data/3.0/onecall` avec un abonnement 4.0 aurait rendu un refus qu'on
     * aurait mis longtemps à comprendre.
     */
    #[DataProvider('versions')]
    public function testChaqueVersionAppelleSonChemin(string $version, string $chemin, string $attendu): void
    {
        $vu = '';

        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'api_key' => null, 'latitude' => 52.2297, 'longitude' => 21.0122,
            'place' => 'Varsovie', 'auto_apply' => 0, 'api_version' => $version,
        ]);

        $service = new OpenWeatherService(
            new WeatherCredentialStore($db, new SecretBox('secret-de-test')),
            self::CLE,
            '52.2297',
            '21.0122',
            'Varsovie',
            'https://exemple.test',
            new MockHttpClient(function (string $m, string $u) use (&$vu): MockResponse {
                $vu = $u;

                return self::reponseHote([[0, 800]]);
            }),
        );

        $service->forecast('2026-08-03', 'fr');

        self::assertStringStartsWith('https://exemple.test' . $chemin . '?', $vu);
        parse_str((string) parse_url($vu, \PHP_URL_QUERY), $q);
        self::assertArrayHasKey($attendu, $q);
        self::assertArrayNotHasKey($attendu === 'cnt' ? 'exclude' : 'cnt', $q);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function versions(): iterable
    {
        yield 'One Call 3.0' => ['3.0', '/data/3.0/onecall', 'exclude'];
        yield 'One Call 4.0' => ['4.0', '/data/4.0/onecall/timeline/1day', 'cnt'];
    }

    // ── Le rattrapage d'historique ──────────────────────────────────────────

    /**
     * L'historique se demande par FENÊTRES, et la boucle avance.
     *
     * Sans l'avance sur le dernier jour rendu, une fenêtre qui ne rend rien de
     * neuf ferait tourner la boucle sur place jusqu'au garde-fou — cinquante
     * appels facturés pour le même mois.
     */
    public function testLHistoriqueAvanceDeFenetreEnFenetre(): void
    {
        $vus = [];

        $service = $this->serviceV4(function (string $m, string $url) use (&$vus): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);
            $depart = (new \DateTimeImmutable('@' . (int) $q['start']))->format('Y-m-d');
            $vus[] = $depart;

            $jours = [];
            for ($i = 0; $i < 30; ++$i) {
                $jours[] = [
                    'dt' => (int) $q['start'] + $i * 86400,
                    'temp' => ['min' => 10.0, 'max' => 20.0],
                    'weather' => [['id' => 800]],
                    'pop' => 0.1,
                ];
            }

            return new MockResponse(json_encode(
                ['timezone_offset' => 0, 'data' => $jours],
                \JSON_THROW_ON_ERROR,
            ));
        });

        $jours = $service->history('2026-04-20', '2026-06-18');

        // Deux fenêtres de trente journées couvrent l'intervalle.
        self::assertSame(['2026-04-20', '2026-05-20'], $vus);
        self::assertCount(60, $jours);
        self::assertSame('2026-04-20', $jours[0]->date);
        self::assertSame('2026-06-18', end($jours)->date);
    }

    /**
     * Ce qui dépasse l'intervalle demandé est JETÉ.
     *
     * La fenêtre rend trente journées d'un bloc : un rattrapage « jusqu'au
     * 30 juin » aurait sinon écrit aussi juillet, et le journal aurait porté
     * des prévisions présentées comme des relevés.
     */
    public function testCeQuiDepasseLIntervalleEstJete(): void
    {
        $service = $this->serviceV4(function (string $m, string $url): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);
            $jours = [];
            for ($i = 0; $i < 30; ++$i) {
                $jours[] = [
                    'dt' => (int) $q['start'] + $i * 86400,
                    'temp' => ['min' => 10.0, 'max' => 20.0],
                    'weather' => [['id' => 500]],
                ];
            }

            return new MockResponse(json_encode(['timezone_offset' => 0, 'data' => $jours], \JSON_THROW_ON_ERROR));
        });

        $jours = $service->history('2026-04-20', '2026-04-24');

        self::assertCount(5, $jours);
        self::assertSame('2026-04-24', end($jours)->date);
    }

    /**
     * La 3.0 ne sait pas remonter le temps ici, et le DIT.
     *
     * Rendre un tableau vide se serait lu comme « il n'a rien fait ces
     * jours-là », et la corrélation aurait tourné sur un journal fantôme.
     */
    public function testLaVersion3RefuseLHistoriqueAuLieuDeRendreLeVide(): void
    {
        $service = $this->service(static fn (): MockResponse => throw new \LogicException('aucun appel ne devait partir'));

        $this->expectException(WeatherUnavailable::class);
        $this->expectExceptionMessage('admin.weather.noHistory');
        $service->history('2026-04-20', '2026-04-30');
    }

    /** Un service réglé sur One Call 4.0. */
    private function serviceV4(callable $reponse): OpenWeatherService
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'api_key' => null, 'latitude' => 52.2297, 'longitude' => 21.0122,
            'place' => 'Varsovie', 'auto_apply' => 0, 'api_version' => '4.0',
        ]);

        return new OpenWeatherService(
            new WeatherCredentialStore($db, new SecretBox('secret-de-test')),
            self::CLE, '52.2297', '21.0122', 'Varsovie',
            'https://exemple.test',
            new MockHttpClient($reponse),
        );
    }

    // ── La clé ne fuit pas ──────────────────────────────────────────────────

    /**
     * LE point sensible.
     *
     * La clé voyage dans la chaîne de requête — OpenWeatherMap n'accepte pas
     * d'en-tête d'autorisation. L'exception de transport de Symfony cite l'URL
     * COMPLÈTE dans son message, et ce message part dans un bandeau d'écran et
     * dans les journaux du serveur. Une clé qu'on a pris soin de chiffrer en
     * base et de ne jamais réafficher s'y retrouvait en clair à la première
     * panne réseau.
     */
    public function testLaClefNApparaitPasQuandLHoteEstInjoignable(): void
    {
        $service = $this->service(static fn (): MockResponse => throw new TransportException(
            'Failed to connect for "https://exemple.test/data/3.0/onecall?lat=52.2&appid='
            . self::CLE . '&units=metric".',
        ));

        try {
            $service->forecast('2026-08-03');
            self::fail('la panne aurait dû être signalée');
        } catch (WeatherUnavailable $e) {
            self::assertSame('admin.weather.unreachable', $e->getMessage());
            self::assertStringNotContainsString(self::CLE, $e->detail);
            self::assertStringNotContainsString(self::CLE, $e->getMessage());
            // Le reste du message est conservé : c'est lui qui dit ce qui
            // s'est passé.
            self::assertStringContainsString('Failed to connect', $e->detail);
            self::assertStringContainsString('appid=…', $e->detail);
        }
    }

    /** Même si c'est l'hôte lui-même qui nous renvoie la clé. */
    public function testLaClefNApparaitPasQuandLHoteLaRepete(): void
    {
        $service = $this->service(new MockResponse(
            '{"cod":401,"message":"Invalid API key ' . self::CLE . ' — see docs"}',
            ['http_code' => 401],
        ));

        try {
            $service->forecast('2026-08-03');
            self::fail('le refus aurait dû être signalé');
        } catch (WeatherUnavailable $e) {
            self::assertStringNotContainsString(self::CLE, $e->detail);
        }
    }

    // ── Les réglages ────────────────────────────────────────────────────────

    public function testLesReglagesDuServeurServentDeRepli(): void
    {
        $service = $this->service(self::reponseHote([[0, 800]]));
        $reglages = $service->credentials();

        self::assertInstanceOf(WeatherCredentials::class, $reglages);
        self::assertSame(52.2297, $reglages->latitude);
        self::assertSame(21.0122, $reglages->longitude);
        self::assertSame('Varsovie', $reglages->place);
        self::assertFalse($reglages->fromScreen);
        self::assertTrue($service->isConfigured());
    }

    /** Des coordonnées absentes du serveur ne se devinent pas en (0, 0). */
    public function testSansCoordonneesLeServiceSeDeclareNonConfigure(): void
    {
        $service = new OpenWeatherService(
            $this->magasinVide(),
            self::CLE,
            '',
            '',
            '',
            'https://exemple.test',
            new MockHttpClient(self::reponseHote([[0, 800]])),
        );

        self::assertFalse($service->isConfigured());
    }
}
