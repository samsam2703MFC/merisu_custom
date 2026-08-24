<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\PosCategory;
use Merisu\Inventory\Domain\PosCredentials;
use Merisu\Inventory\Domain\PosItem;
use Merisu\Inventory\Store\PosCredentialStore;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * La caisse GoPOS, en lecture.
 *
 * ── Le jeton se demande, il ne se configure pas
 *
 * `POST /oauth/token`, `grant_type=organization`, avec les trois valeurs de
 * l'administrateur. Le jeton revient avec sa durée de vie ; il est gardé POUR
 * LA DURÉE DE LA REQUÊTE seulement. Le mettre en cache entre deux requêtes
 * aurait obligé à stocker un secret d'accès quelque part, pour économiser un
 * appel sur un écran qu'on ouvre trois fois par semaine.
 *
 * ── La pagination est suivie jusqu'au bout
 *
 * GoPOS rend vingt lignes par défaut, cent au maximum. Une boutique en a
 * facilement trois cents : s'arrêter à la première page aurait importé le
 * début du catalogue et laissé croire que c'était tout.
 */
final class GoPosService implements PosServiceInterface
{
    /**
     * Cent, le maximum annoncé par la caisse.
     *
     * Demander moins aurait multiplié les allers-retours sans rien gagner ;
     * demander plus fait retomber la caisse sur sa valeur par défaut, et l'on
     * repartirait pour vingt lignes par page.
     */
    private const PAGE_SIZE = 100;

    /**
     * Garde-fou contre une pagination qui ne se termine pas.
     *
     * Cent pages font dix mille articles — bien au-delà d'une pâtisserie. Si
     * la boucle y arrive, c'est que la caisse rend toujours la même page, et
     * mieux vaut s'arrêter que tourner sans fin sur un écran d'administration.
     */
    private const MAX_PAGES = 100;

    private const TIMEOUT = 20.0;

    /** L'adresse de production GoPOS, quand rien n'est réglé. */
    public const DEFAULT_BASE_URL = 'https://app.gopos.io';

    private ?string $token = null;

    public function __construct(
        private readonly PosCredentialStore $credentialStore,
        #[\SensitiveParameter]
        private readonly string $envClientId = '',
        #[\SensitiveParameter]
        private readonly string $envClientSecret = '',
        private readonly string $envOrganizationId = '',
        private readonly string $envBaseUrl = 'https://app.gopos.io',
        private readonly ?HttpClientInterface $http = null,
    ) {
    }

    /**
     * Les identifiants en vigueur : l'ÉCRAN d'abord, le serveur ensuite.
     *
     * L'écran l'emporte parce que c'est le geste le plus récent et le plus
     * délibéré — quelqu'un vient de taper trois valeurs et attend qu'elles
     * s'appliquent. Une variable d'environnement qui les aurait silencieusement
     * recouvertes aurait donné un écran où l'on saisit sans effet.
     */
    public function credentials(): PosCredentials
    {
        return $this->credentialStore->effective(new PosCredentials(
            $this->envClientId,
            $this->envClientSecret,
            $this->envOrganizationId,
            trim($this->envBaseUrl) !== '' ? $this->envBaseUrl : self::DEFAULT_BASE_URL,
        ));
    }

    public function isConfigured(): bool
    {
        return $this->credentials()->isComplete();
    }

    public function ping(): string
    {
        // Les réglages de l'organisation : le plus petit appel authentifié qui
        // prouve à la fois que le jeton est valide ET que l'organisation
        // existe. Un simple /oauth/token n'aurait prouvé que la première.
        $reglages = $this->get('/settings');
        $donnees = is_array($reglages['data'] ?? null) ? $reglages['data'] : $reglages;

        foreach (['name', 'organization_name', 'company_name'] as $champ) {
            if (is_string($donnees[$champ] ?? null) && trim($donnees[$champ]) !== '') {
                return trim($donnees[$champ]);
            }
        }

        // La caisse n'a pas donné de nom : on rend l'identifiant plutôt qu'un
        // « connexion réussie » qui ne dirait pas QUELLE boutique répond.
        return $this->credentials()->organizationId;
    }

    public function categories(): array
    {
        $categories = [];

        foreach ($this->pages('/categories') as $ligne) {
            $categorie = PosCategory::fromHost($ligne);

            if ($categorie !== null) {
                $categories[] = $categorie;
            }
        }

        return $categories;
    }

    public function items(): array
    {
        $articles = [];

        foreach ($this->pages('/items') as $ligne) {
            $article = PosItem::fromHost($ligne);

            if ($article !== null) {
                $articles[] = $article;
            }
        }

        return $articles;
    }

    // ── Transport ───────────────────────────────────────────────────────────

    /**
     * Parcourt toutes les pages d'une collection.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function pages(string $chemin): \Generator
    {
        $page = 1;
        $vues = 0;

        do {
            $reponse = $this->get($chemin, ['page' => $page, 'size' => self::PAGE_SIZE]);
            $lignes = is_array($reponse['data'] ?? null) ? $reponse['data'] : [];

            foreach ($lignes as $ligne) {
                if (is_array($ligne)) {
                    yield $ligne;
                }
            }

            $vues = count($lignes);
            ++$page;
            // Une page incomplète est la dernière : la caisse n'annonce pas
            // toujours un total, et compter les lignes est le seul repère sur
            // lequel on puisse s'appuyer partout.
        } while ($vues >= self::PAGE_SIZE && $page <= self::MAX_PAGES);
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $chemin, array $query = []): array
    {
        if (!$this->isConfigured()) {
            throw new PosUnavailable('admin.pos.notConfigured');
        }

        $identifiants = $this->credentials();
        $url = rtrim($identifiants->baseUrl, '/') . '/api/v3/'
            . rawurlencode($identifiants->organizationId) . $chemin;

        try {
            $reponse = $this->client()->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'],
                'query' => $query,
                'timeout' => self::TIMEOUT,
            ]);

            $statut = $reponse->getStatusCode();
            $corps = $reponse->getContent(false);

            if ($statut >= 400) {
                // Le jeton a été accepté puisqu'on en a obtenu un : un refus
                // ici parle de l'ORGANISATION ou du droit d'accès, pas des
                // identifiants. Les confondre envoyait l'administrateur
                // revérifier un secret qui était bon.
                throw new PosUnavailable(
                    $statut === 401 || $statut === 403 ? 'admin.pos.accessRefused' : 'admin.pos.hostRefused',
                    self::detail($statut, $corps),
                );
            }

            return $this->decode($corps);
        } catch (TransportException | HttpExceptionInterface $e) {
            throw new PosUnavailable('admin.pos.unreachable', $e->getMessage(), $e);
        }
    }

    /**
     * Le jeton, demandé une fois par requête.
     *
     * ── Deux formes d'envoi, parce que la spécification n'en fixe aucune
     *
     * `/oauth/token` n'est PAS décrit dans le swagger GoPOS : la seule
     * consigne est une phrase de prose — « Request should include params as
     * grant_type, client_id, client_secret, organization_id » — et « params »
     * désigne aussi bien un corps de formulaire qu'une chaîne de requête.
     *
     * On tente donc le corps de formulaire, qui est la forme normale d'OAuth,
     * puis la chaîne de requête si la caisse a refusé. Deviner une fois coûte
     * un aller-retour ; se tromper définitivement aurait coûté une intégration.
     *
     * ── Ce que la caisse a depuis répondu
     *
     * Interrogée avec des identifiants factices, `app.gopos.io` accepte les
     * DEUX formes et rend la même erreur OAuth. Le corps de formulaire est
     * donc bel et bien lu — un corps JSON, lui, ne l'est pas : il rend un
     * « Unauthorized » nu, sans `error`, signe qu'aucun identifiant n'y a été
     * trouvé.
     *
     * Le repli est donc devenu inutile. Il est gardé : il ne coûte un appel de
     * plus que sur le chemin d'échec, et rien ne dit qu'une installation
     * GoPOS sur un autre hôte se comporte comme celle-ci.
     *
     * @throws PosUnavailable
     */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $identifiants = $this->credentials();
        $url = rtrim($identifiants->baseUrl, '/') . '/oauth/token';

        $params = [
            'grant_type' => 'organization',
            'client_id' => $identifiants->clientId,
            'client_secret' => $identifiants->clientSecret,
            'organization_id' => $identifiants->organizationId,
        ];

        $dernierDetail = '';

        foreach ([['body' => $params], ['query' => $params]] as $forme) {
            try {
                $reponse = $this->client()->request('POST', $url, $forme + [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => self::TIMEOUT,
                ]);

                $statut = $reponse->getStatusCode();
                $corpsBrut = $reponse->getContent(false);
            } catch (TransportException | HttpExceptionInterface $e) {
                throw new PosUnavailable('admin.pos.unreachable', $e->getMessage(), $e);
            }

            if ($statut >= 400) {
                // On retient ce que la caisse a dit, et on essaie l'autre
                // forme. Si les deux échouent, c'est ce message-là qui
                // remontera à l'écran — le seul indice exploitable.
                $dernierDetail = self::detail($statut, $corpsBrut);

                continue;
            }

            $corps = $this->decode($corpsBrut);
            $jeton = $corps['access_token'] ?? null;

            if (is_string($jeton) && $jeton !== '') {
                return $this->token = $jeton;
            }

            $dernierDetail = self::detail($statut, $corpsBrut);
        }

        throw new PosUnavailable(self::tokenReason($dernierDetail), $dernierDetail);
    }

    /**
     * Quel message afficher, selon ce que la caisse a répondu.
     *
     * ── Ce que `invalid_client` dit, et ce qu'il ne dit pas
     *
     * GoPOS valide le CLIENT avant tout le reste. Constaté sur
     * `app.gopos.io` : avec un client inconnu, la réponse est la même
     * — 401 `invalid_client` / « Bad client credentials » — que
     * l'`organization_id` soit juste, faux ou absent, et que le `grant_type`
     * soit celui attendu ou n'importe quoi d'autre.
     *
     * Autrement dit, quand la caisse dit `invalid_client`, elle n'a PAS encore
     * regardé l'Organization ID. Envoyer l'administrateur le vérifier,
     * c'était le faire chercher une faute là où la caisse n'a
     * rien reproché — et passer à côté de la seule
     * paire en cause.
     */
    private static function tokenReason(string $detail): string
    {
        return str_contains($detail, 'invalid_client')
            ? 'admin.pos.badClient'
            : 'admin.pos.tokenRefused';
    }

    /**
     * Ce que la caisse a répondu, résumé pour l'écran.
     *
     * Le corps est TRONQUÉ : une page d'erreur HTML de plusieurs kilo-octets
     * n'apprend rien de plus que ses premières lignes, et remplirait l'écran.
     * Le secret n'y figure jamais — il ne part que dans la requête.
     */
    private static function detail(int $status, string $body): string
    {
        $resume = 'HTTP ' . $status;

        /** @var mixed $corps */
        $corps = json_decode($body, true);

        if (is_array($corps)) {
            $morceaux = [];

            foreach (['error', 'error_description', 'message', 'description'] as $champ) {
                if (is_string($corps[$champ] ?? null) && trim($corps[$champ]) !== '') {
                    $morceaux[] = trim($corps[$champ]);
                }
            }

            if ($morceaux !== []) {
                return $resume . ' — ' . mb_substr(implode(' · ', array_unique($morceaux)), 0, 300);
            }
        }

        $texte = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return $texte === '' ? $resume : $resume . ' — ' . mb_substr($texte, 0, 300);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            /** @var mixed $brut */
            $brut = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PosUnavailable('admin.pos.badAnswer', mb_substr(trim($json), 0, 300), $e);
        }

        return is_array($brut) ? $brut : throw new PosUnavailable('admin.pos.badAnswer', mb_substr(trim($json), 0, 300));
    }

    private function client(): HttpClientInterface
    {
        return $this->http ?? throw new PosUnavailable('admin.pos.notConfigured');
    }
}
