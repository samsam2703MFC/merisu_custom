/**
 * @merisu/domain — noyau de calcul du module Inventaire & Production.
 *
 * Ce paquet est volontairement PUR : aucune dépendance à Express, React, Prisma
 * ou à une base de données. Il est partagé entre l'API et la PWA, ce qui garantit
 * que le consultant et l'admin voient exactement le même chiffre.
 */

export * from './types.js';
export * from './date.js';
export * from './rounding.js';
export * from './netVariance.js';
export * from './production.js';
export * from './validation.js';
export * from './delta.js';
export * from './i18nText.js';
