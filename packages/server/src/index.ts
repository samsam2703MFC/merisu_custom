/** Point d'entrée du serveur. */

import { loadConfig } from './config.js';
import { buildContext } from './context.js';
import { createApp } from './http/app.js';
import { DEMO_PAR_MATRIX, DEMO_PRODUCTS } from './seedData.js';

const config = loadConfig();
const ctx = await buildContext({ config });

// Premier démarrage à vide : on pose les 8 emplacements et la matrice fictive,
// sinon l'écran de saisie serait vide et l'application indémontrable.
const products = await ctx.store.listProducts();
if (products.length === 0) {
  for (const product of DEMO_PRODUCTS) await ctx.store.upsertProduct(product);
  await ctx.store.upsertParEntries(DEMO_PAR_MATRIX);
  console.log('[init] 8 emplacements produits + matrice PLACEHOLDER créés (à ajuster en admin)');
}

const app = createApp(ctx);

const server = app.listen(config.port, () => {
  console.log(`[api] MERISU Inventaire & Production — port ${config.port} (store: ${config.store})`);
});

for (const signal of ['SIGINT', 'SIGTERM'] as const) {
  process.on(signal, () => {
    server.close(() => {
      void ctx.store.close().then(() => process.exit(0));
    });
  });
}
