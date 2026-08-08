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

            if ($statut === 401 || $statut === 403) {
                throw new PosUnavailable('admin.pos.badCredentials');
            }

            if ($statut >= 400) {
                throw new PosUnavailable('admin.pos.hostRefused');
            }

            return $this->decode($reponse->getContent(false));
        } catch (TransportException | HttpExceptionInterface $e) {
            throw new PosUnavailable('admin.pos.unreachable', previous: $e);
        }
    }

    /**
     * Le jeton, demandé une fois par requête.
     *
     * @throws PosUnavailable
     */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        try {
            $identifiants = $this->credentials();

            $reponse = $this->client()->request('POST', rtrim($identifiants->baseUrl, '/') . '/oauth/token', [
                'headers' => ['Accept' => 'application/json'],
                // Le contrat GoPOS : « grant_type = organization », et les
                // trois valeurs de l'administrateur. Rien d'autre.
                'body' => [
                    'grant_type' => 'organization',
                    'client_id' => $identifiants->clientId,
                    'client_secret' => $identifiants->clientSecret,
                    'organization_id' => $identifiants->organizationId,
                ],
                'timeout' => self::TIMEOUT,
            ]);

            if ($reponse->getStatusCode() >= 400) {
                throw new PosUnavailable('admin.pos.badCredentials');
            }

            $corps = $this->decode($reponse->getContent(false));
        } catch (TransportException | HttpExceptionInterface $e) {
            throw new PosUnavailable('admin.pos.unreachable', previous: $e);
        }

        $jeton = $corps['access_token'] ?? null;

        if (!is_string($jeton) || $jeton === '') {
            throw new PosUnavailable('admin.pos.badAnswer');
        }

        return $this->token = $jeton;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            /** @var mixed $brut */
            $brut = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PosUnavailable('admin.pos.badAnswer', previous: $e);
        }

        return is_array($brut) ? $brut : throw new PosUnavailable('admin.pos.badAnswer');
    }

    private function client(): HttpClientInterface
    {
        return $this->http ?? throw new PosUnavailable('admin.pos.notConfigured');
    }
}
