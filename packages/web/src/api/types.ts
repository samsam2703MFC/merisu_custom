/** Formes des réponses de l'API, alignées sur les services serveur. */

import type {
  Consultant,
  CountMoment,
  DailyNet,
  GeneralSettings,
  InventoryCount,
  Locale,
  MaterialDelta,
  Material,
  ParMatrixEntry,
  Product,
  ProductionPlan,
  Workstation,
} from '@merisu/domain';

export interface LoginResponse {
  token: string;
  consultant: Consultant;
  workstationId: string | null;
}

export interface MeResponse {
  consultant: Consultant;
  workstationId: string | null;
  settings: GeneralSettings;
}

export interface DaySheet {
  date: string;
  workstationId: string;
  moment: CountMoment;
  settings: GeneralSettings;
  products: Product[];
  counts: InventoryCount[];
  validated: boolean;
  validatedAt: string | null;
  validatedBy: string | null;
  planForToday: ProductionPlan[];
  dailyNets: DailyNet[];
}

export interface PlanResponse {
  forDate: string;
  dayOfWeek: string;
  computedFromDate: string;
  lines: ProductionPlan[];
  frozen: boolean;
}

export interface PlanPreview {
  forDate: string;
  forDayOfWeek: string;
  workstationId: string;
  lines: Array<{
    productId: string;
    productCode: string;
    requiredPieces: number;
    closingStock: number;
    rawQtyToProduce: number;
    qtyToProduce: number;
    missingThreshold: boolean;
    missingClosingStock: boolean;
  }>;
  warningsMissingThreshold: string[];
}

export interface ValidateResponse {
  counts: InventoryCount[];
  plan: ProductionPlan[];
}

export interface DeltaReport {
  from: string;
  to: string;
  workstationId?: string;
  deltas: MaterialDelta[];
  summary: { materials: number; outOfTolerance: number; partial: boolean };
  tolerance: number;
  producedByProduct: Record<string, number>;
  productsWithoutRecipe: string[];
  materialsWithoutRealData: string[];
  partial: boolean;
}

export interface MatrixCell {
  required: number | null;
  opening: number | null;
  closing: number | null;
  produced: number | null;
  net: number | null;
}

export interface MatrixReport {
  from: string;
  to: string;
  days: Array<{ date: string; dayOfWeek: string }>;
  products: Array<{ id: string; code: string; unit: string }>;
  cells: Record<string, Record<string, MatrixCell>>;
}

export interface AuditEntry {
  id: string;
  at: string;
  actorId: string;
  actorRole: string;
  action: string;
  workstationId: string | null;
  businessDate: string | null;
  details: Record<string, unknown>;
}

export interface MaterialMovement {
  id: string;
  date: string;
  materialId: string;
  realQty: number;
  workstationId: string | null;
  recordedBy: string;
  recordedAt: string;
  note: string | null;
}

export type { Consultant, GeneralSettings, Locale, Material, ParMatrixEntry, Product, Workstation };
