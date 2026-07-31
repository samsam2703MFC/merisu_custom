/**
 * Seed de démonstration.
 *
 * Crée les 8 emplacements produits et une matrice de seuils FICTIVE, pour rendre
 * l'application démontrable immédiatement. Idempotent : rejouable sans doublon.
 *
 * Usage :
 *   npm run seed -w @merisu/server                       (store mémoire)
 *   STORE=postgres DATABASE_URL=… npm run seed -w @merisu/server
 */

import { loadConfig } from './config.js';
import { DEMO_PAR_MATRIX, DEMO_PRODUCTS } from './seedData.js';
import { createStore } from './store/index.js';

async function main(): Promise<void> {
  const config = loadConfig();
  const store = await createStore(config);

  try {
    const existing = await store.listProducts();
    const existingCodes = new Set(existing.map((p) => p.code));

    for (const product of DEMO_PRODUCTS) {
      if (existingCodes.has(product.code)) {
        console.log(`· ${product.code} déjà présent, conservé tel quel`);
        continue;
      }
      await store.upsertProduct(product);
      console.log(`+ ${product.code} créé`);
    }

    const products = await store.listProducts();
    const idByCode = new Map(products.map((p) => [p.code, p.id]));
    const existingMatrix = await store.listParMatrix();

    if (existingMatrix.length > 0) {
      console.log(`· matrice des seuils déjà remplie (${existingMatrix.length} lignes), inchangée`);
    } else {
      const entries = DEMO_PAR_MATRIX.map((entry) => {
        const code = DEMO_PRODUCTS.find((p) => p.id === entry.productId)?.code;
        return { ...entry, productId: idByCode.get(code ?? '') ?? entry.productId };
      });
      await store.upsertParEntries(entries);
      console.log(`+ ${entries.length} seuils fictifs créés`);
    }

    console.log('\nSeed terminé.');
    console.log('⚠️  Données PLACEHOLDER : à remplacer via Admin ▸ Produits et Admin ▸ Seuils.');
    console.log('    Comptes de démonstration : admin/0000, consultant1/1111, consultant2/2222');
  } finally {
    await store.close();
  }
}

main().catch((error) => {
  console.error('Échec du seed :', error);
  process.exit(1);
});
