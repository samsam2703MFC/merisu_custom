/** Contexte applicatif injecté dans les routes (composition root). */

import type { ConsultantService } from '../adapters/consultantService.js';
import type { RecipeService } from '../adapters/recipeService.js';
import type { ServerConfig } from '../config.js';
import type { InventoryService } from '../services/inventoryService.js';
import type { ReportService } from '../services/reportService.js';
import type { Store } from '../store/types.js';

export interface AppContext {
  config: ServerConfig;
  store: Store;
  consultants: ConsultantService;
  recipes: RecipeService;
  inventory: InventoryService;
  reports: ReportService;
}
