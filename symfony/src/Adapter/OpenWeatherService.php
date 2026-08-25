<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\ForecastDay;
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

    /**
     * Le chemin, et les paramètres qui vont avec, selon la version.
     *
     * ── Ce qui change entre 3.0 et 4.0, et ce qui ne change pas
     *
     * 3.0 rend tout d'un coup — actuel, minute, heure, jour — et l'on écarte
     * le superflu par `exclude`. 4.0 découpe en « timelines » : `15min`, `1h`,
     * `1day`. C'est `1day` qu'il nous faut, et il se demande par `cnt`, non
     * par `exclude`.
     *
     * Les JOURNÉES, elles, sont identiques : mêmes `dt`, `temp.min`,
     * `temp.max`, `weather[].id`, `pop`, même `timezone_offset` à la racine.
     * Seul le nom du tableau diffère — `daily` contre `data` — et
     * `WeatherForecast::fromHost` accepte les deux.
     *
     * ⚠️ Les deux versions exigent le MÊME abonnement « One Call by Call ».
     * Basculer de l'une à l'autre ne fait pas disparaître un 401 : l'hôte rend
     * la même phrase, au numéro de version près.
     */
    private const ENDPOINTS = [
        WeatherCredentials::VERSION_3 => '/data/3.0/onecall',
        WeatherCredentials::VERSION_4 => '/data/4.0/onecall/timeline/1day',
    ];

    /**
     * Journées demandées à la 4.0.
     *
     * Dix pour sept gardées : `cnt` compte à partir d'aujourd'hui, mais rien
     * ne dit si la journée en cours entre dans le lot, et une prévision qui
     * s'arrêterait au sixième jour se remarquerait un dimanche soir. Le
     * domaine coupe à sept de toute façon.
     */
    private const V4_COUNT = 10;

    /** Journées demandées par fenêtre d'historique. */
    private const HISTORY_COUNT = 30;

    /** Garde-fou : cinquante fenêtres de trente jours font quatre ans. */
    private const MAX_HISTORY_WINDOWS = 50;

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
        $version = WeatherCredentials::cleanVersion($reglages->apiVersion);
        $url = rtrim(trim($this->envBaseUrl) !== '' ? $this->envBaseUrl : self::DEFAULT_BASE_URL, '/')
            . self::ENDPOINTS[$version];

        try {
            $reponse = $this->client()->request('GET', $url, [
                'query' => [
                    'lat' => $reglages->latitude,
                    'lon' => $reglages->longitude,
                    'appid' => $reglages->apiKey,
                    // Celsius : la boutique est en Europe, et un seuil relu en
                    // Fahrenheit par erreur ne se remarque pas tout de suite.
                    // Sans lui, l'hôte rend des KELVIN — 288,16 pour 15 °C.
                    'units' => 'metric',
                    'lang' => in_array($lang, self::LANGS, true) ? $lang : 'en',
                ] + ($version === WeatherCredentials::VERSION_4
                    ? ['cnt' => self::V4_COUNT]
                    // 3.0 rend tout : la minute par minute sur une heure et
                    // l'heure par heure sur deux jours, plusieurs centaines de
                    // lignes dont ce module ne fait rien.
                    : ['exclude' => 'current,minutely,hourly,alerts']),
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
     * Le temps qu'il a FAIT, par fenêtres.
     *
     * ── Seule la 4.0 sait remonter le temps ici
     *
     * Sa série journalière prend un `start` — un horodatage — et un `cnt`, et
     * rend les journées à partir de là ; ses liens `prev` et `next` disent
     * assez qu'elle est faite pour ça. La 3.0 a bien un `timemachine`, mais il
     * rend UNE journée heure par heure, dans une autre forme : le brancher
     * demanderait un second analyseur pour un chemin que cette maison
     * n'emprunte pas. On refuse franchement plutôt que de rendre un tableau
     * vide, qui se serait lu comme « il n'a rien fait ces jours-là ».
     *
     * ── Par fenêtres, et facturé à la fenêtre
     *
     * Chaque appel compte dans le quota. Trente journées par appel : quatre
     * mois d'historique coûtent cinq appels, sur un millier offerts par jour.
     *
     * @return list<ForecastDay>
     */
    public function history(string $from, string $to, string $lang = 'en'): array
    {
        $reglages = $this->credentials();

        if (!$reglages->isComplete()) {
            throw new WeatherUnavailable('admin.weather.notConfigured');
        }

        if (WeatherCredentials::cleanVersion($reglages->apiVersion) !== WeatherCredentials::VERSION_4) {
            throw new WeatherUnavailable('admin.weather.noHistory');
        }

        $jours = [];
        $curseur = $from;

        for ($fenetre = 0; $fenetre < self::MAX_HISTORY_WINDOWS && $curseur <= $to; ++$fenetre) {
            $lot = $this->historyWindow($reglages, $curseur, $lang);

            if ($lot === []) {
                break;
            }

            $dernier = $curseur;

            foreach ($lot as $jour) {
                // La fenêtre déborde volontiers de l'intervalle demandé : on
                // ne garde que ce qu'on a demandé, faute de quoi un relevé
                // « jusqu'au 30 juin » écrirait aussi juillet.
                if ($jour->date >= $from && $jour->date <= $to) {
                    $jours[$jour->date] = $jour;
                }

                $dernier = max($dernier, $jour->date);
            }

            // Le lendemain du dernier jour rendu. Sans cette avance, une
            // fenêtre qui ne rend rien de neuf ferait tourner la boucle sur
            // place jusqu'au garde-fou, en consommant le quota.
            $suivant = (new \DateTimeImmutable($dernier))->modify('+1 day')->format('Y-m-d');

            if ($suivant <= $curseur) {
                break;
            }

            $curseur = $suivant;
        }

        ksort($jours);

        return array_values($jours);
    }

    /**
     * Une fenêtre d'historique.
     *
     * @return list<ForecastDay>
     */
    private function historyWindow(WeatherCredentials $reglages, string $depuis, string $lang): array
    {
        $url = rtrim(trim($this->envBaseUrl) !== '' ? $this->envBaseUrl : self::DEFAULT_BASE_URL, '/')
            . self::ENDPOINTS[WeatherCredentials::VERSION_4];

        try {
            $reponse = $this->client()->request('GET', $url, [
                'query' => [
                    'lat' => $reglages->latitude,
                    'lon' => $reglages->longitude,
                    'appid' => $reglages->apiKey,
                    'units' => 'metric',
                    'lang' => in_array($lang, self::LANGS, true) ? $lang : 'en',
                    // Midi, et non minuit : une journée demandée à minuit
                    // tombe la veille dès que le fuseau de la boutique est à
                    // l'est de Greenwich, et le relevé glisserait d'un jour.
                    'start' => (new \DateTimeImmutable($depuis . ' 12:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                    'cnt' => self::HISTORY_COUNT,
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

        // `fromHost` borne à sept jours et jette le passé : c'est ce qu'il faut
        // pour une prévision, jamais pour un historique. On lit donc les
        // journées directement.
        $charge = $this->decode($corps);
        $decalage = is_numeric($charge['timezone_offset'] ?? null) ? (int) $charge['timezone_offset'] : 0;
        $lignes = is_array($charge['data'] ?? null) ? $charge['data'] : ($charge['daily'] ?? []);

        $jours = [];

        foreach (is_array($lignes) ? $lignes : [] as $ligne) {
            $jour = is_array($ligne) ? ForecastDay::fromHost($ligne, $decalage) : null;

            if ($jour !== null) {
                $jours[] = $jour;
            }
        }

        return $jours;
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
