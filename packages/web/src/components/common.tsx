/** Composants d'interface partagés. */

import { SUPPORTED_LOCALES, resolveI18nText, type I18nText, type Locale } from '@merisu/domain';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { LOCALE_LABELS, changeLocale, currentLocale } from '../i18n/index.js';

export function Banner({
  kind = 'info',
  children,
}: {
  kind?: 'info' | 'ok' | 'warn' | 'danger';
  children: ReactNode;
}) {
  return (
    <div className={`banner ${kind}`} role={kind === 'danger' ? 'alert' : 'status'}>
      {children}
    </div>
  );
}

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <section className={`card ${className}`}>{children}</section>;
}

export function Spinner() {
  const { t } = useTranslation();
  return <p className="muted">{t('common.loading')}</p>;
}

/** Sélecteur de langue — présent sur tous les écrans, y compris la connexion (§7.1). */
export function LanguageSwitcher() {
  const { t, i18n } = useTranslation();
  const active = currentLocale();

  return (
    <label className="row" style={{ gap: '0.4rem', margin: 0 }}>
      <span className="sr-only" style={{ position: 'absolute', left: '-9999px' }}>
        {t('common.language')}
      </span>
      <select
        aria-label={t('common.language')}
        value={active}
        onChange={(event) => changeLocale(event.target.value as Locale)}
        style={{ minWidth: 120, width: 'auto' }}
        key={i18n.language}
      >
        {SUPPORTED_LOCALES.map((locale) => (
          <option key={locale} value={locale}>
            {LOCALE_LABELS[locale]}
          </option>
        ))}
      </select>
    </label>
  );
}

/**
 * Affiche un libellé de DONNÉE (nom de produit, de matière) dans la langue courante.
 * À ne pas confondre avec `t()`, réservé aux textes d'interface.
 */
export function useDataLabel(): (text: I18nText | null | undefined, fallback?: string) => string {
  const { i18n } = useTranslation();
  const locale = (i18n.language?.split('-')[0] ?? 'fr') as Locale;

  return (text, fallback = '') => resolveI18nText(text, locale, 'fr', fallback);
}

/** Formate une quantité sans imposer de décimales inutiles. */
export function formatQty(value: number | null | undefined, locale: string): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 3 }).format(value);
}

export function formatDateTime(iso: string | null | undefined, locale: string): string {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '—';
  return new Intl.DateTimeFormat(locale, { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

export function formatTime(iso: string | null | undefined, locale: string): string {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '—';
  return new Intl.DateTimeFormat(locale, { timeStyle: 'short' }).format(date);
}

export function formatDate(isoDate: string, locale: string): string {
  const date = new Date(`${isoDate}T12:00:00Z`);
  if (Number.isNaN(date.getTime())) return isoDate;
  return new Intl.DateTimeFormat(locale, { dateStyle: 'full' }).format(date);
}

/** Boîte de dialogue de confirmation (validation du soir, déverrouillage…). */
export function ConfirmDialog({
  title,
  body,
  confirmLabel,
  onConfirm,
  onCancel,
  busy = false,
}: {
  title: string;
  body: ReactNode;
  confirmLabel: string;
  onConfirm: () => void;
  onCancel: () => void;
  busy?: boolean;
}) {
  const { t } = useTranslation();

  return (
    <div className="dialog-backdrop" role="dialog" aria-modal="true" aria-label={title}>
      <div className="dialog">
        <h2>{title}</h2>
        <div className="muted" style={{ marginBottom: '1rem' }}>
          {body}
        </div>
        <div className="row">
          <button type="button" className="secondary" onClick={onCancel} disabled={busy}>
            {t('common.cancel')}
          </button>
          <div className="spacer" />
          <button type="button" onClick={onConfirm} disabled={busy}>
            {busy ? t('common.saving') : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
