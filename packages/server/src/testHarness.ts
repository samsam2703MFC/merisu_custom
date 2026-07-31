/** Montage d'une application de test : store mémoire volatil, adaptateurs locaux. */

import type { DayOfWeek } from '@merisu/domain';

import { LocalConsultantService } from './adapters/consultantService.js';
import { LocalRecipeService } from './adapters/recipeService.js';
import { loadConfig, type ServerConfig } from './config.js';
import { buildContext } from './context.js';
import { createApp } from './http/app.js';
import { DEMO_CONSULTANTS, DEMO_MATERIALS, DEMO_RECIPES, DEMO_WORKSTATIONS } from './seedData.js';
import { MemoryStore } from './store/memory.js';
import type { Store } from './store/types.js';

export interface TestHarness {
  app: ReturnType<typeof createApp>;
  store: Store;
  config: ServerConfig;
}

export async function createTestHarness(
  options: { seedProducts?: number; parMatrix?: Array<[string, DayOfWeek, number]> } = {},
): Promise<TestHarness> {
  const config = loadConfig({
    STORE: 'memory',
    AUTH_SECRET: 'test-secret',
    UPLOAD_DIR: 'test-uploads',
  } as NodeJS.ProcessEnv);

  const store = new MemoryStore();
  await store.init();

  const productCount = options.seedProducts ?? 3;
  for (let i = 1; i <= productCount; i += 1) {
    await store.upsertProduct({
      id: `product-${i}`,
      code: `PRODUIT_${i}`,
      name: { fr: `Slot ${i}` },
      unit: 'pcs',
      active: true,
      wasteFactor: 0,
      roundingStep: 1,
      roundingMode: 'CEIL',
      recipeRef: null,
      sortOrder: i,
    });
  }

  if (options.parMatrix) {
    await store.upsertParEntries(
      options.parMatrix.map(([productId, dayOfWeek, requiredPieces]) => ({
        productId,
        dayOfWeek,
        requiredPieces,
        workstationId: null,
      })),
    );
  }

  const ctx = await buildContext({
    config,
    store,
    consultants: new LocalConsultantService(DEMO_CONSULTANTS, DEMO_WORKSTATIONS),
    recipes: new LocalRecipeService(DEMO_RECIPES, DEMO_MATERIALS),
  });

  return { app: createApp(ctx), store, config };
}

/** Connecte un compte de démonstration et renvoie son jeton. */
export async function login(
  app: TestHarness['app'],
  login_: string,
  secret: string,
  workstationId?: string,
): Promise<string> {
  const request = (await import('supertest')).default;
  const response = await request(app)
    .post('/api/auth/login')
    .send({ login: login_, secret, workstationId });

  if (response.status !== 200) {
    throw new Error(`Connexion échouée (${response.status}) : ${JSON.stringify(response.body)}`);
  }
  return response.body.token as string;
}
