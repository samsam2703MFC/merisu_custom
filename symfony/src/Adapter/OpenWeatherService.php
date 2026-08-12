<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\WeatherCredentials;
use Merisu\Inventory\Domain\WeatherForecast;
use Merisu\Inventory\Store\WeatherCredentialStore;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenWeatherMap, offre « One Call by Call ».
 *
 * ── Un seul appel, et il est FACTURÉ
 *
 * L'offre compte mille appels par jour offerts, puis facture à l'appel. Un
 * écran d'administration qui interrogerait l'hôte à chaque affichage aurait
 * consommé le quota d'une boutique en une matinée de mise au point, sans que
 * personne ne s'en aperçoive avant la facture. C'est pourquoi ce service n'est
 * appelé que sur demande explicite ou par la tâche planifiée, et pourquoi la
 * réponse est gardée en base : les écrans lisent le cache, jamais l'hôte.
 *
 * ── `exclude` n'est pas une optimisation cosmétique
 *
 * La réponse complète porte la minute par minute sur une heure et l'heure par
 * heure sur deux jours — plusieurs centaines de lignes dont ce module ne fait
 * rien. On demande donc `daily` seul. La facturation ne change pas ; ce qu'on
 * évite, c'est de décoder un demi-mégaoctet pour en garder sept lignes.
 *
 * ── La clé 3.0 n'est pas la clé 2.5
 *
 * One Call 3.0 se souscrit séparément. Une clé valable pour le reste
 * d'OpenWeatherMap y répond 401 avec une phrase qui l'explique — d'où le
 * détail remonté tel quel à l'écran plutôt qu'un « clé invalide » qui aurait
 * envoyé chercher une faute de frappe inexistante.
 */
final class OpenWeatherService implements WeatherServiceInterface
{
    public const DEFAULT_BASE_URL = 'https://api.openweathermap.org';

    private const ENDPOINT = '/data/3.0/onecall';

    private const TIMEOUT = 15.0;

    /**
     * Les langues que ce module parle, et qu'OpenWeatherMap parle aussi.
     *
     * Une langue inconnue de l'hôte ne fait pas échouer l'appel : elle rend
     * des libellés anglais. On préfère demander l'anglais franchement.
     */
    private const LANGS = ['fr', 'pl', 'it', 'es', 'en'];

    public function __construct(
        private readonly WeatherCredentialStore $credentialStore,
        #[\SensitiveParameter]
        private readonly string $envApiKey = '',
        private readonly string $envLatitude = '',
        private readonly string $envLongitude = '',
        private readonly string $envPlace = '',
        private readonly string $envBaseUrl = self::DEFAULT_BASE_URL,
        private readonly ?HttpClientInterface $http = null,
    ) {
    }

    /**
     * Les réglages en vigueur : l'ÉCRAN d'abord, le serveur ensuite.
     *
     * Même règle que la caisse, et pour la même raison : quelqu'un vient de
     * saisir une clé et attend qu'elle s'applique. Une variable
     * d'environnement qui l'aurait silencieusement recouverte aurait donné un
     * écran où l'on saisit sans effet.
     */
    public function credentials(): WeatherCredentials
    {
        return $this->credentialStore->effective(new WeatherCredentials(
            $this->envApiKey,
            is_numeric($this->envLatitude) ? (float) $this->envLatitude : 0.0,
            is_numeric($this->envLongitude) ? (float) $this->envLongitude : 0.0,
            trim($this->envPlace),
        ));
    }

    public function isConfigured(): bool
    {
        return $this->credentials()->isComplete();
    }

    public function forecast(string $today, string $lang = 'en'): WeatherForecast
    {
        $reglages = $this->credentials();

        if (!$reglages->isComplete()) {
            throw new WeatherUnavailable('admin.weather.notConfigured');
        }

        // L'adresse ne se saisit PAS à l'écran, contrairement à celle de la
        // caisse : OpenWeatherMap n'a qu'un hôte, et un champ de plus n'aurait
        // servi qu'à le casser. Elle reste réglable par l'environnement, pour
        // qu'un test puisse viser un bouchon local.
        $url = rtrim(trim($this->envBaseUrl) !== '' ? $this->envBaseUrl : self::DEFAULT_BASE_URL, '/')
            . self::ENDPOINT;

        try {
            $reponse = $this->client()->request('GET', $url, [
                'query' => [
                    'lat' => $reglages->latitude,
                    'lon' => $reglages->longitude,
                    'appid' => $reglages->apiKey,
                    // Celsius : la boutique est en Europe, et un seuil relu en
                    // Fahrenheit par erreur ne se remarque pas tout de suite.
                    'units' => 'metric',
                    'exclude' => 'current,minutely,hourly,alerts',
                    'lang' => in_array($lang, self::LANGS, true) ? $lang : 'en',
                ],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
            ]);

            $statut = $reponse->getStatusCode();
            $corps = $reponse->getContent(false);
        } catch (TransportException | HttpExceptionInterface $e) {
            throw new WeatherUnavailable('admin.weather.unreachable', $this->redact($e->getMessage()), $e);
        }

        if ($statut >= 400) {
            throw new WeatherUnavailable(self::reason($statut), $this->redact(self::detail($statut, $corps)));
        }

        $prevision = WeatherForecast::fromHost($this->decode($corps), $today);

        // Une réponse acceptée mais vide de journées exploitables ne doit pas
        // passer pour une semaine sans météo : elle effacerait une prévision
        // valable en base. On la traite comme un refus.
        if ($prevision->isEmpty()) {
            throw new WeatherUnavailable('admin.weather.badAnswer', $this->redact(self::detail($statut, $corps)));
        }

        return $prevision;
    }

    /**
     * Le message d'écran qui correspond au code HTTP.
     *
     * Trois cas se distinguent parce que trois gestes différents les
     * réparent : renouveler l'abonnement, attendre demain, ou corriger les
     * coordonnées. Un message unique les aurait tous renvoyés vers la clé.
     */
    private static function reason(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'admin.weather.badKey',
            $status === 429 => 'admin.weather.quota',
            $status === 400 || $status === 404 => 'admin.weather.badPlace',
            default => 'admin.weather.hostRefused',
        };
    }

    /**
     * Efface la clé de tout ce qui va s'afficher.
     *
     * ── Ce n'est pas de la prudence de principe
     *
     * La clé voyage dans la CHAÎNE DE REQUÊTE — OpenWeatherMap n'accepte pas
     * d'en-tête d'autorisation. Or l'exception de transport de Symfony cite
     * l'URL complète dans son message : « Failed to connect to … ?…&appid=…&… ».
     * Ce message finit dans un bandeau d'écran et dans la sortie de la tâche
     * planifiée, c'est-à-dire dans les journaux du serveur. Une clé qu'on a
     * pris soin de chiffrer en base et de ne jamais réafficher se retrouvait
     * ainsi en clair à la première panne réseau.
     *
     * Deux passes, parce qu'aucune ne suffit seule : la valeur exacte, quelle
     * que soit la forme où elle apparaît, et le paramètre `appid` quoi qu'il
     * porte — au cas où l'hôte renverrait la clé recodée, ou une autre.
     */
    private function redact(string $texte): string
    {
        $cle = trim($this->credentials()->apiKey);

        if ($cle !== '') {
            $texte = str_replace([$cle, rawurlencode($cle)], '…', $texte);
        }

        return (string) preg_replace('/(appid=)[^&\s"\')]+/i', '$1…', $texte);
    }

    /**
     * Ce que l'hôte a répondu, résumé pour l'écran.
     *
     * Tronqué : une page d'erreur HTML de plusieurs kilo-octets n'apprend rien
     * de plus que ses premières lignes. Passé à `redact()` avant d'être
     * montré — voir là-bas pourquoi ce n'est pas facultatif.
     */
    private static function detail(int $status, string $body): string
    {
        $resume = 'HTTP ' . $status;

        /** @var mixed $corps */
        $corps = json_decode($body, true);

        if (is_array($corps)) {
            foreach (['message', 'error', 'error_description'] as $champ) {
                if (is_string($corps[$champ] ?? null) && trim($corps[$champ]) !== '') {
                    return $resume . ' — ' . mb_substr(trim($corps[$champ]), 0, 300);
                }
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
            throw new WeatherUnavailable('admin.weather.badAnswer', mb_substr(trim($json), 0, 300), $e);
        }

        return is_array($brut)
            ? $brut
            : throw new WeatherUnavailable('admin.weather.badAnswer', mb_substr(trim($json), 0, 300));
    }

    private function client(): HttpClientInterface
    {
        return $this->http ?? throw new WeatherUnavailable('admin.weather.notConfigured');
    }
}
