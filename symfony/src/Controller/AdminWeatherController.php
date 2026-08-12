<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\WeatherServiceInterface;
use Merisu\Inventory\Adapter\WeatherUnavailable;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\ForecastService;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Service\SecretBox;
use Merisu\Inventory\Store\Store;
use Merisu\Inventory\Store\WeatherCredentialStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Météo — la semaine qui vient, et ce qu'elle change au plan.
 *
 * ── Pourquoi cet onglet existe séparément des seuils
 *
 * Le temps attendu se choisit déjà dans Admin ▸ Seuils, jour par jour, à la
 * main. Cet onglet ne le remplace pas : il l'ALIMENTE. On y règle une clé et
 * un endroit — des réglages qu'on pose une fois — et on y relit ce que le
 * service annonce avant de décider de l'appliquer. Mettre une clé d'API au
 * milieu du tableau des seuils aurait mélangé un réglage d'installation avec
 * un geste hebdomadaire.
 *
 * ── L'écran ne va RIEN chercher tout seul
 *
 * Chaque appel est facturé. Ouvrir l'onglet lit la base ; c'est le bouton
 * « Actualiser » — ou la tâche planifiée `merisu:weather` — qui interroge
 * l'hôte. Un affichage qui aurait déclenché l'appel aurait consommé le quota
 * d'une boutique en une matinée de mise au point.
 */
#[Route('/admin/meteo')]
final class AdminWeatherController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly WeatherServiceInterface $weather,
        private readonly ForecastService $forecast,
        private readonly InventoryService $inventory,
        private readonly Store $store,
        private readonly WeatherCredentialStore $credentials,
        private readonly SecretBox $box,
    ) {
    }

    #[Route('', name: 'admin_weather', methods: ['GET'])]
    public function index(): Response
    {
        $this->currentUser->requireAdmin();

        $reglages = $this->weather->credentials();
        $aujourdhui = $this->inventory->today();

        return $this->render('admin/weather.html.twig', [
            'configured' => $this->weather->isConfigured(),
            'credentials' => $reglages->display(),
            'fromScreen' => $reglages->fromScreen,
            'canStore' => $this->box->isAvailable(),
            // La prévision GARDÉE, pas celle de l'hôte : lire est gratuit,
            // demander est facturé.
            'forecast' => $this->forecast->cached($aujourdhui),
            'fetchedAt' => $this->forecast->fetchedAt(),
            'today' => $aujourdhui,
            // Le temps actuellement en vigueur dans la semaine type : c'est
            // lui qui pilote le plan, et l'écart avec la prévision est
            // exactement ce que le bouton « Appliquer » viendrait corriger.
            'dayWeathers' => $this->store->dayWeathers(),
            'weatherRatios' => $this->store->weatherRatios(),
        ]);
    }

    /**
     * Va chercher la prévision chez l'hôte — un appel, facturé.
     *
     * En POST, et non en lien : un lien se recharge d'un coup de F5, et se
     * fait précharger par le navigateur. Une centaine d'appels facturés
     * seraient partis sans que personne ne clique.
     */
    #[Route('/actualiser', name: 'admin_weather_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        try {
            $resultat = $this->forecast->refresh(
                $this->inventory->today(),
                // Les libellés de l'hôte suivent la langue de l'écran : c'est
                // la seule partie de la prévision qui se lit en toutes lettres.
                $request->getLocale(),
                $admin->id,
                $admin->role->value,
            );
        } catch (WeatherUnavailable $e) {
            $this->addFlash('error', ['key' => $e->getMessage(), 'params' => []]);

            if ($e->detail !== '') {
                $this->addFlash('error', ['key' => 'admin.weather.hostSaid', 'params' => ['%detail%' => $e->detail]]);
            }

            return $this->redirectToRoute('admin_weather');
        }

        $this->addFlash('success', [
            'key' => $resultat['autoApplied'] ? 'admin.weather.refreshedApplied' : 'admin.weather.refreshed',
            'params' => ['%days%' => $resultat['days'], '%applied%' => $resultat['applied']],
        ]);

        return $this->redirectToRoute('admin_weather');
    }

    /**
     * Recopie la prévision gardée dans la semaine type.
     *
     * Ne relit pas l'hôte : c'est la prévision AFFICHÉE que l'on applique,
     * celle qu'on vient de regarder. En chercher une nouvelle au moment du
     * clic aurait appliqué autre chose que ce qui était à l'écran — et coûté
     * un appel de plus.
     */
    #[Route('/appliquer', name: 'admin_weather_apply', methods: ['POST'])]
    public function apply(): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $prevision = $this->forecast->cached($this->inventory->today());

        if ($prevision->isEmpty()) {
            $this->addFlash('error', 'admin.weather.nothingToApply');

            return $this->redirectToRoute('admin_weather');
        }

        $changes = $this->forecast->apply($prevision, $admin->id, $admin->role->value);

        $this->addFlash('success', ['key' => 'admin.weather.applied', 'params' => ['%count%' => $changes]]);

        return $this->redirectToRoute('admin_weather');
    }

    /**
     * Enregistre la clé et l'endroit.
     *
     * La clé est en ÉCRITURE SEULE, comme le secret de la caisse : laissée
     * vide, elle n'est pas effacée — sans cette règle, corriger une latitude
     * aurait effacé une clé que personne ne peut relire pour la retaper.
     */
    #[Route('/reglages', name: 'admin_weather_settings', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        // Sans chiffrement possible, on n'écrit RIEN : mieux vaut un écran qui
        // refuse et l'explique qu'une clé facturée posée en clair dans une base
        // que l'on sauvegarde toutes les nuits.
        if (!$this->box->isAvailable()) {
            $this->addFlash('error', 'admin.weather.errorNoCrypto');

            return $this->redirectToRoute('admin_weather');
        }

        $latitude = self::coordinate($request->request->get('latitude'));
        $longitude = self::coordinate($request->request->get('longitude'));

        // Des coordonnées hors du globe ne rendraient pas une erreur : elles
        // rendraient la météo d'ailleurs, ou rien, sans le dire. On refuse ici
        // plutôt que d'aller le demander à un service facturé.
        if (abs($latitude) > 90.0 || abs($longitude) > 180.0) {
            $this->addFlash('error', 'admin.weather.errorBadCoordinates');

            return $this->redirectToRoute('admin_weather');
        }

        try {
            $this->credentials->save(
                (string) $request->request->get('apiKey', ''),
                $latitude,
                $longitude,
                mb_substr(trim((string) $request->request->get('place', '')), 0, 190),
                $request->request->getBoolean('autoApply'),
            );
        } catch (\RuntimeException) {
            $this->addFlash('error', 'admin.weather.errorNoCrypto');

            return $this->redirectToRoute('admin_weather');
        }

        // Ni la clé, ni son empreinte : l'historique se consulte en
        // administration, et n'a pas à en porter la trace.
        $this->store->audit($admin->id, $admin->role->value, 'WEATHER_SETTINGS_SAVED', null, null, [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'autoApply' => $request->request->getBoolean('autoApply'),
            'keyChanged' => trim((string) $request->request->get('apiKey', '')) !== '',
        ]);

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_weather');
    }

    /** Efface la saisie d'écran : la configuration du serveur reprend la main. */
    #[Route('/reglages/effacer', name: 'admin_weather_settings_clear', methods: ['POST'])]
    public function clearSettings(): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $this->credentials->clear();

        $this->store->audit($admin->id, $admin->role->value, 'WEATHER_SETTINGS_CLEARED');
        $this->addFlash('success', 'admin.weather.settingsCleared');

        return $this->redirectToRoute('admin_weather');
    }

    /**
     * Une coordonnée saisie au clavier.
     *
     * La virgule est acceptée comme séparateur : « 52,23 » est ce que tape un
     * clavier français ou polonais, et le refuser sans rien dire aurait donné
     * une latitude de 52 — cent kilomètres plus au nord.
     */
    private static function coordinate(mixed $value): float
    {
        return is_scalar($value) ? (float) str_replace(',', '.', trim((string) $value)) : 0.0;
    }
}
