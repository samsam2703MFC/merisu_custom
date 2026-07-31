/**
 * Types partagés du module Inventaire & Production.
 *
 * IMPORTANT : aucun produit, seuil ou recette n'est codé en dur ici.
 * Tout provient de la base / de l'administration.
 */

/** Les 4 langues obligatoires du projet. */
export const SUPPORTED_LOCALES = ['fr', 'pl', 'it', 'es'] as const;
export type Locale = (typeof SUPPORTED_LOCALES)[number];

/** Libellé traduisible : { fr: "Crème 1", pl: "Krem 1", ... }. Partiel = repli sur la langue par défaut. */
export type I18nText = Partial<Record<Locale, string>>;

/** Rôles applicatifs (cf. §2 du cahier des charges). */
export type Role = 'CONSULTANT' | 'ADMIN';

/**
 * Jour de la semaine, en clé stable et indépendante de la langue.
 * L'index correspond à `Date#getDay()` décalé pour démarrer le lundi.
 */
export const DAYS_OF_WEEK = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'] as const;
export type DayOfWeek = (typeof DAYS_OF_WEEK)[number];

/** Moment de comptage dans la journée. */
export type CountMoment = 'OPEN_0800' | 'CLOSE_2200';

/** Mode d'arrondi appliqué à la quantité à produire. */
export type RoundingMode =
  /** Arrondi au multiple supérieur (défaut : on ne produit jamais en dessous du besoin). */
  | 'CEIL'
  /** Arrondi au multiple inférieur. */
  | 'FLOOR'
  /** Arrondi au multiple le plus proche. */
  | 'NEAREST'
  /** Aucun arrondi : la valeur décimale est conservée (matières au gramme, au ml…). */
  | 'NONE';

/** Produit — 8 emplacements génériques, renommables et activables dans l'admin. */
export interface Product {
  id: string;
  /** PRODUIT_1 … PRODUIT_8 — code stable, jamais affiché tel quel à l'utilisateur. */
  code: string;
  /** Libellé multilingue, saisi par l'admin. */
  name: I18nText;
  /** Unité de comptage : pièce, g, ml… (libre, paramétrée en admin). */
  unit: string;
  active: boolean;
  /** Facteur de perte/marge, ex. 0.05 = +5 % produits en plus. Défaut 0. */
  wasteFactor: number;
  /** Pas d'arrondi (1 = à la pièce, 6 = par lot de 6, 0.1 = au décilitre…). */
  roundingStep: number;
  roundingMode: RoundingMode;
  /** Référence vers la recette du module existant « Product Recipe Technique ». */
  recipeRef: string | null;
  sortOrder: number;
}

/** Matière première (lecture depuis le module recette / stock matière). */
export interface Material {
  id: string;
  name: I18nText;
  unit: string;
  /** Stock courant si géré ; null si non suivi. */
  currentStock: number | null;
}

/** Ligne de nomenclature : quantité de matière par unité de produit fini. */
export interface RecipeLine {
  materialId: string;
  qtyPerUnit: number;
}

/** Recette / BOM d'un produit — module existant, lecture seule ici. */
export interface Recipe {
  productId: string;
  lines: RecipeLine[];
}

/** Seuil : pièces requises en stock pour un produit un jour donné (« par level »). */
export interface ParMatrixEntry {
  id: string;
  productId: string;
  dayOfWeek: DayOfWeek;
  requiredPieces: number;
  /** Optionnel : seuil spécifique à un poste. null = seuil global. (Question ouverte §10.) */
  workstationId: string | null;
}

/** Comptage physique d'un produit à un moment donné. */
export interface InventoryCount {
  id: string;
  /** Date métier au format ISO `YYYY-MM-DD` (jour de production, pas l'horodatage). */
  date: string;
  workstationId: string;
  consultantId: string;
  productId: string;
  moment: CountMoment;
  qty: number;
  /** Quantité produite et entrée en stock dans la journée (optionnel, cf. §5.1). */
  producedQty?: number | null;
  validated: boolean;
  validatedAt: string | null;
  validatedBy: string | null;
  photos: CountPhoto[];
  createdAt: string;
  updatedAt: string;
}

export interface CountPhoto {
  id: string;
  inventoryCountId: string;
  url: string;
  takenAt: string;
}

export type ProductionPlanStatus = 'DRAFT' | 'FROZEN' | 'CANCELLED';

/** Ligne du plan de production à réaliser pour le lendemain matin. */
export interface ProductionPlan {
  id: string;
  /** Date visée (le lendemain), `YYYY-MM-DD`. */
  forDate: string;
  productId: string;
  workstationId: string;
  /** Seuil lu dans la matrice pour le jour visé. */
  requiredPieces: number;
  /** Stock de clôture 22:00 du jour courant. */
  closingStock: number;
  /** Résultat du calcul §5.2, arrondi et majoré du facteur de perte. */
  qtyToProduce: number;
  computedAt: string;
  status: ProductionPlanStatus;
  /** true si aucun seuil n'était défini pour (produit, jour) → avertissement admin. */
  missingThreshold: boolean;
}

/** Écart net journalier (§5.1) — dérivé, non stocké. */
export interface DailyNet {
  date: string;
  productId: string;
  workstationId: string;
  openingStock: number | null;
  closingStock: number | null;
  producedQty: number;
  /** Ouverture + production − clôture. null si une des saisies manque. */
  netConsumption: number | null;
  /** true dès qu'une donnée nécessaire manque (affichage « données partielles »). */
  partial: boolean;
}

/** Delta technique par matière (§6). */
export interface MaterialDelta {
  materialId: string;
  theoreticalQty: number;
  realQty: number | null;
  /** réel − théorique. null si la consommation réelle n'est pas connue. */
  delta: number | null;
  /** delta / théorique. null si théorique = 0 ou réel inconnu. */
  deltaPct: number | null;
  /** true si au-delà de la tolérance paramétrée. */
  outOfTolerance: boolean;
  partial: boolean;
}

/** Paramètres généraux, tous modifiables en admin (§3.3.3). */
export interface GeneralSettings {
  /** Heure du comptage d'ouverture, format `HH:mm`. */
  openingTime: string;
  /** Heure du comptage de clôture, format `HH:mm`. */
  closingTime: string;
  /** Fuseau horaire IANA, ex. `Europe/Warsaw`. */
  timezone: string;
  defaultLocale: Locale;
  /** Photo obligatoire au comptage du soir ? */
  photoRequired: boolean;
  /** Une photo par produit (true) ou une photo globale suffit (false). */
  photoPerProduct: boolean;
  /** Tolérance du delta technique, ex. 0.05 = 5 %. */
  deltaTolerance: number;
}

/** Poste de travail (« stanowisko ») — provient du module Consultant existant. */
export interface Workstation {
  id: string;
  name: string;
  active: boolean;
}

/** Consultant — provient du module Consultant existant. */
export interface Consultant {
  id: string;
  displayName: string;
  role: Role;
  /** Poste pré-affecté, si le module existant en fournit un. */
  defaultWorkstationId: string | null;
  active: boolean;
}
