import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

/**
 * PWA installable (§2) : manifeste + service worker Workbox.
 *
 * Les appels API ne sont PAS mis en cache en écriture : la saisie hors-ligne
 * passe par notre propre file d'attente IndexedDB (`src/offline/queue.ts`), qui
 * garantit l'ordre et l'idempotence du rejeu. Le service worker ne gère que le
 * cache de l'application et la lecture des référentiels.
 */
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'icons/*.png'],
      manifest: {
        name: 'MERISU — Inventaire & Production',
        short_name: 'MERISU',
        description: 'Comptage des stocks 08:00 / 22:00 et plan de production',
        theme_color: '#7b3f00',
        background_color: '#faf7f2',
        display: 'standalone',
        orientation: 'any',
        start_url: '/',
        scope: '/',
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          {
            src: 'icons/icon-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
        navigateFallback: 'index.html',
        runtimeCaching: [
          {
            // Référentiels (produits, paramètres) : lisibles hors-ligne, rafraîchis dès que possible.
            urlPattern: ({ url, request }) =>
              request.method === 'GET' && /\/api\/(admin\/(products|settings)|workstations)/.test(url.pathname),
            handler: 'StaleWhileRevalidate',
            options: { cacheName: 'merisu-reference' },
          },
          {
            // Feuille de saisie : le réseau prime, le cache dépanne au poste hors-ligne.
            urlPattern: ({ url, request }) =>
              request.method === 'GET' && /\/api\/(day-sheet|production-plan)/.test(url.pathname),
            handler: 'NetworkFirst',
            options: {
              cacheName: 'merisu-day',
              networkTimeoutSeconds: 4,
              expiration: { maxEntries: 60, maxAgeSeconds: 60 * 60 * 48 },
            },
          },
        ],
      },
      devOptions: { enabled: false },
    }),
  ],
  server: {
    port: 5173,
    proxy: apiProxy(),
  },
  // `vite preview` ne réutilise pas la configuration `server` : le proxy doit y
  // être répété, sinon la version compilée n'atteint plus l'API.
  preview: {
    port: 4173,
    proxy: apiProxy(),
  },
});

/** Renvoie l'API et les photos vers le serveur applicatif pendant le développement. */
function apiProxy() {
  const target = process.env.API_URL ?? 'http://localhost:3001';
  return {
    '/api': { target, changeOrigin: true },
    '/uploads': { target, changeOrigin: true },
  };
}
