/**
 * Valeurs par défaut des paramètres généraux.
 *
 * Ce sont des DÉFAUTS TECHNIQUES de premier démarrage, pas des données métier :
 * l'admin les modifie depuis l'écran « Paramètres généraux », sans redéploiement.
 */

import type { GeneralSettings } from '@merisu/domain';

export const DEFAULT_SETTINGS: GeneralSettings = {
  openingTime: '08:00',
  closingTime: '22:00',
  timezone: 'Europe/Warsaw',
  defaultLocale: 'fr',
  photoRequired: true,
  photoPerProduct: false,
  deltaTolerance: 0.05,
};

/** Nombre d'emplacements produits créés au premier démarrage (§4). */
export const PRODUCT_SLOT_COUNT = 8;
