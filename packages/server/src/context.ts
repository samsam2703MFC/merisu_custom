/**
 * Composition root : c'est ICI que le module est branché sur l'existant.
 *
 * Pour raccorder les vrais modules Consultant et Recette, il suffit de remplacer
 * les implémentations locales passées à `createConsultantService` /
 * `createRecipeService` — aucun autre fichier n'est à toucher.
 */

import {
  LocalConsultantService,
  createConsultantService,
  type ConsultantService,
  type LocalConsultantRecord,
} from './adapters/consultantService.js';
import {
  LocalRecipeService,
  createRecipeService,
  type RecipeService,
} from './adapters/recipeService.js';
import type { ServerConfig } from './config.js';
import type { AppContext } from './http/context.js';
import { InventoryService } from './services/inventoryService.js';
import { ReportService } from './services/reportService.js';
import { createStore } from './store/index.js';
import type { Store } from './store/types.js';
import { DEMO_CONSULTANTS, DEMO_MATERIALS, DEMO_RECIPES, DEMO_WORKSTATIONS } from './seedData.js';

export interface BuildContextOptions {
  config: ServerConfig;
  /** Store déjà initialisé (tests) ; sinon créé depuis la configuration. */
  store?: Store;
  /** Adaptateurs de substitution (tests, ou branchement du vrai module). */
  consultants?: ConsultantService;
  recipes?: RecipeService;
}

export async function buildContext(options: BuildContextOptions): Promise<AppContext> {
  const store = options.store ?? (await createStore(options.config));

  const consultants =
    options.consultants ??
    createConsultantService(
      () =>
        new LocalConsultantService(
          DEMO_CONSULTANTS as LocalConsultantRecord[],
          DEMO_WORKSTATIONS,
        ),
    );

  const recipes =
    options.recipes ?? createRecipeService(() => new LocalRecipeService(DEMO_RECIPES, DEMO_MATERIALS));

  return {
    config: options.config,
    store,
    consultants,
    recipes,
    inventory: new InventoryService(store),
    reports: new ReportService(store, recipes),
  };
}
