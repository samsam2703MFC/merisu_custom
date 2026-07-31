/**
 * Internationalisation (§2) — FR / PL / IT / ES, commutables par l'utilisateur.
 *
 * Aucune chaîne d'interface n'est écrite dans les composants : tout passe par ces
 * fichiers. Les noms de produits et de matières, eux, sont des DONNÉES saisies en
 * admin et résolus par `resolveI18nText` du domaine.
 */

import { SUPPORTED_LOCALES, detectLocale, type Locale } from '@merisu/domain';
import i18next from 'i18next';
import { initReactI18next } from 'react-i18next';

import es from './locales/es.json';
import fr from './locales/fr.json';
import it from './locales/it.json';
import pl from './locales/pl.json';

const LOCALE_STORAGE_KEY = 'merisu.locale';

export const LOCALE_LABELS: Record<Locale, string> = {
  fr: 'Français',
  pl: 'Polski',
  it: 'Italiano',
  es: 'Español',
};

/**
 * Langue initiale : choix explicite mémorisé, sinon détection navigateur
 * (§2 — « détection navigateur au premier lancement »), sinon langue par défaut.
 * La langue par défaut de l'admin est appliquée ensuite, à la connexion.
 */
function initialLocale(): Locale {
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
  if (stored && (SUPPORTED_LOCALES as readonly string[]).includes(stored)) {
    return stored as Locale;
  }
  return detectLocale(navigator.languages ?? [navigator.language], 'fr');
}

void i18next.use(initReactI18next).init({
  resources: {
    fr: { translation: fr },
    pl: { translation: pl },
    it: { translation: it },
    es: { translation: es },
  },
  lng: initialLocale(),
  fallbackLng: 'fr',
  interpolation: { escapeValue: false },
});

export function changeLocale(locale: Locale): void {
  localStorage.setItem(LOCALE_STORAGE_KEY, locale);
  void i18next.changeLanguage(locale);
}

/** L'utilisateur a-t-il déjà choisi une langue ? Sinon, la valeur admin peut primer. */
export function hasExplicitLocale(): boolean {
  return localStorage.getItem(LOCALE_STORAGE_KEY) !== null;
}

/** Applique la langue par défaut définie en admin, sans écraser un choix utilisateur. */
export function applyDefaultLocale(locale: Locale): void {
  if (hasExplicitLocale()) return;
  void i18next.changeLanguage(locale);
}

export function currentLocale(): Locale {
  const lang = i18next.language?.split('-')[0] ?? 'fr';
  return (SUPPORTED_LOCALES as readonly string[]).includes(lang) ? (lang as Locale) : 'fr';
}

export default i18next;
