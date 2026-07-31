/**
 * Résolution des libellés multilingues stockés en base (noms de produits, de matières).
 *
 * Les textes de l'INTERFACE vivent dans les fichiers i18next du front ; ceux-ci
 * sont des données saisies par l'admin, elles ne peuvent donc pas y figurer.
 */

import { SUPPORTED_LOCALES, type I18nText, type Locale } from './types.js';

export function isSupportedLocale(value: string): value is Locale {
  return (SUPPORTED_LOCALES as readonly string[]).includes(value);
}

/**
 * Renvoie le libellé dans la langue demandée, avec repli en cascade :
 * langue demandée → langue par défaut → première langue renseignée → `fallback`.
 */
export function resolveI18nText(
  text: I18nText | null | undefined,
  locale: Locale,
  defaultLocale: Locale = 'fr',
  fallback = '',
): string {
  if (!text) return fallback;

  const direct = text[locale];
  if (direct && direct.trim() !== '') return direct;

  const byDefault = text[defaultLocale];
  if (byDefault && byDefault.trim() !== '') return byDefault;

  for (const candidate of SUPPORTED_LOCALES) {
    const value = text[candidate];
    if (value && value.trim() !== '') return value;
  }

  return fallback;
}

/** Détecte la langue depuis les préférences navigateur, avec repli sur la langue par défaut. */
export function detectLocale(
  navigatorLanguages: readonly string[],
  defaultLocale: Locale,
): Locale {
  for (const raw of navigatorLanguages) {
    const short = raw.toLowerCase().split('-')[0] ?? '';
    if (isSupportedLocale(short)) return short;
  }
  return defaultLocale;
}
