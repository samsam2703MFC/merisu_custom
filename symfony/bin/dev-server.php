<?php

declare(strict_types=1);

/*
 * Routeur pour le serveur web intégré de PHP, en développement uniquement.
 *
 *   php -S 127.0.0.1:8000 -t public bin/dev-server.php
 *
 * Sans lui, le serveur intégré transmet certaines extensions (.svg, .png) au
 * contrôleur frontal au lieu de servir le fichier, ce qui casse le manifeste et
 * l'installation du service worker.
 *
 * Renvoyer `false` indique à PHP de servir lui-même le fichier statique.
 * En production, Nginx ou Apache s'en charge et ce fichier n'est jamais utilisé.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/../public' . urldecode($path);

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/../public/index.php';
