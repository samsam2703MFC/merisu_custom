<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Manifeste et service worker, servis dynamiquement.
 *
 * POURQUOI PAS DES FICHIERS STATIQUES : l'application peut être installée à la
 * racine du domaine ou dans un sous-répertoire (`/merisu/`, `/kitchen/merisu/`…).
 * Un manifeste statique avec `"start_url": "/"` enverrait l'utilisateur sur la
 * mauvaise page une fois l'application installée, et un service worker aux
 * chemins absolus ne trouverait aucune de ses ressources.
 *
 * En les générant ici, les chemins suivent toujours le préfixe réel.
 */
final class PwaController extends AbstractController
{
    #[Route('/manifest.json', name: 'pwa_manifest', methods: ['GET'])]
    public function manifest(Request $request): JsonResponse
    {
        // Racine effective de l'application : « / », « /merisu/ »…
        $base = rtrim($request->getBasePath(), '/') . '/';

        $response = new JsonResponse([
            'name' => 'MERISU — Inventaire & Production',
            'short_name' => 'MERISU',
            'description' => 'Comptage des stocks 08:00 / 22:00 et plan de production',
            'start_url' => $base,
            'scope' => $base,
            'display' => 'standalone',
            'orientation' => 'any',
            'theme_color' => '#FAF7F2',
            'background_color' => '#F8F3E8',
            'icons' => [
                ['src' => $base . 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $base . 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => $base . 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ]);

        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }

    /**
     * Service worker.
     *
     * Servi depuis la racine de l'application pour que sa portée couvre tout le
     * module : un service worker ne contrôle que les pages situées sous son
     * propre chemin.
     */
    #[Route('/sw.js', name: 'pwa_service_worker', methods: ['GET'])]
    public function serviceWorker(): Response
    {
        $response = $this->render('pwa/sw.js.twig');
        $response->headers->set('Content-Type', 'application/javascript');
        // Le navigateur doit revoir le script à chaque déploiement.
        $response->headers->set('Cache-Control', 'no-cache');

        return $response;
    }

    /** URL absolue du service worker, utilisée par app.js pour l'enregistrer. */
    public function serviceWorkerUrl(): string
    {
        return $this->generateUrl('pwa_service_worker', [], UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
