/**
 * Utilitaires de date métier.
 *
 * Toutes les dates métier circulent en `YYYY-MM-DD` (chaîne), jamais en `Date`,
 * afin d'éviter les décalages de fuseau entre le poste du consultant et le serveur.
 */

import { DAYS_OF_WEEK, type DayOfWeek } from './types.js';

const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

export function isIsoDate(value: string): boolean {
  if (!ISO_DATE_RE.test(value)) return false;
  const [y, m, d] = value.split('-').map(Number) as [number, number, number];
  const dt = new Date(Date.UTC(y, m - 1, d));
  return dt.getUTCFullYear() === y && dt.getUTCMonth() === m - 1 && dt.getUTCDate() === d;
}

function assertIsoDate(value: string): void {
  if (!isIsoDate(value)) {
    throw new RangeError(`Date métier invalide (attendu YYYY-MM-DD) : "${value}"`);
  }
}

/** Convertit `YYYY-MM-DD` en Date UTC (midi UTC pour rester insensible au DST). */
function toUtcDate(isoDate: string): Date {
  assertIsoDate(isoDate);
  const [y, m, d] = isoDate.split('-').map(Number) as [number, number, number];
  return new Date(Date.UTC(y, m - 1, d, 12, 0, 0));
}

function toIsoDate(date: Date): string {
  const y = String(date.getUTCFullYear()).padStart(4, '0');
  const m = String(date.getUTCMonth() + 1).padStart(2, '0');
  const d = String(date.getUTCDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/**
 * Jour de la semaine d'une date métier, en clé stable (MON…SUN).
 * `Date#getUTCDay()` renvoie 0 pour dimanche : on décale pour démarrer au lundi.
 */
export function dayOfWeekOf(isoDate: string): DayOfWeek {
  const jsDay = toUtcDate(isoDate).getUTCDay();
  const index = (jsDay + 6) % 7;
  return DAYS_OF_WEEK[index]!;
}

/** Jour suivant. Gère nativement le passage de semaine (dimanche → lundi) et de mois/année. */
export function nextDay(isoDate: string): string {
  const dt = toUtcDate(isoDate);
  dt.setUTCDate(dt.getUTCDate() + 1);
  return toIsoDate(dt);
}

/** Jour précédent. */
export function previousDay(isoDate: string): string {
  const dt = toUtcDate(isoDate);
  dt.setUTCDate(dt.getUTCDate() - 1);
  return toIsoDate(dt);
}

/** Ajoute (ou retire) un nombre de jours à une date métier. */
export function addDays(isoDate: string, days: number): string {
  const dt = toUtcDate(isoDate);
  dt.setUTCDate(dt.getUTCDate() + days);
  return toIsoDate(dt);
}

/** Liste inclusive des dates métier entre `from` et `to`. */
export function dateRange(from: string, to: string): string[] {
  assertIsoDate(from);
  assertIsoDate(to);
  if (from > to) return [];
  const out: string[] = [];
  let cursor = from;
  // Garde-fou : une période de rapport ne dépasse jamais ~10 ans.
  for (let i = 0; i < 4000 && cursor <= to; i += 1) {
    out.push(cursor);
    cursor = nextDay(cursor);
  }
  return out;
}

/**
 * Date métier « aujourd'hui » dans le fuseau configuré (§3.3.3).
 * Utilise l'API Intl pour éviter d'embarquer une librairie de fuseaux.
 */
export function businessToday(timezone: string, now: Date = new Date()): string {
  try {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(now);
    // `en-CA` produit déjà YYYY-MM-DD.
    return parts;
  } catch {
    // Fuseau inconnu : repli sur UTC plutôt que planter la saisie du consultant.
    return toIsoDate(now);
  }
}

/** Heure locale `HH:mm` dans le fuseau configuré (sert à proposer le bon écran matin/soir). */
export function businessTime(timezone: string, now: Date = new Date()): string {
  try {
    return new Intl.DateTimeFormat('en-GB', {
      timeZone: timezone,
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(now);
  } catch {
    return `${String(now.getUTCHours()).padStart(2, '0')}:${String(now.getUTCMinutes()).padStart(2, '0')}`;
  }
}

/** Compare deux heures `HH:mm`. Renvoie <0, 0 ou >0. */
export function compareTime(a: string, b: string): number {
  return a.localeCompare(b);
}
