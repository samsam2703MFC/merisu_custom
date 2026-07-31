/** Traduction des codes d'erreur API (§2 — aucune chaîne en dur). */

import { useTranslation } from 'react-i18next';

import { ApiError, NetworkError } from '../api/client.js';

export function useApiError(): (error: unknown) => string {
  const { t } = useTranslation();

  return (error: unknown): string => {
    if (error instanceof NetworkError) return t('errors.network');
    if (error instanceof ApiError) {
      // Le serveur renvoie une clé stable ; si elle n'est pas encore traduite,
      // on retombe sur un message générique plutôt que d'afficher la clé brute.
      return t(`errors.${error.code}`, { defaultValue: t('errors.generic') });
    }
    return t('errors.generic');
  };
}
