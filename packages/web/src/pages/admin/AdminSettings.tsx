/** §7.7 — Paramètres généraux : horaires, fuseau, langue, photo, tolérance. */

import { SUPPORTED_LOCALES, type GeneralSettings, type Locale } from '@merisu/domain';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../../api/client.js';
import { Banner, Card, Spinner } from '../../components/common.js';
import { LOCALE_LABELS } from '../../i18n/index.js';
import { useApiError } from '../../hooks/useApiError.js';
import { useSession } from '../../state/session.js';

export function AdminSettings() {
  const { t } = useTranslation();
  const { refreshSettings } = useSession();
  const translateError = useApiError();

  const [settings, setSettings] = useState<GeneralSettings | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    api
      .settings()
      .then((response) => setSettings(response.settings))
      .catch((caught) => setError(translateError(caught)))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function patch(changes: Partial<GeneralSettings>): void {
    setSettings((current) => (current ? { ...current, ...changes } : current));
    setSaved(false);
  }

  async function save(): Promise<void> {
    if (!settings) return;
    setSaving(true);
    setError(null);

    try {
      const response = await api.updateSettings(settings);
      setSettings(response.settings);
      // La session porte les paramètres (horaires, photo) utilisés par la saisie.
      await refreshSettings();
      setSaved(true);
    } catch (caught) {
      setError(translateError(caught));
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <Spinner />;
  if (!settings) return <Banner kind="danger">{error ?? t('errors.generic')}</Banner>;

  return (
    <>
      <h1>{t('admin.settings.title')}</h1>

      {error && <Banner kind="danger">{error}</Banner>}
      {saved && <Banner kind="ok">{t('common.saved')}</Banner>}

      <Card>
        <div className="field-grid">
          <div className="field">
            <label htmlFor="openingTime">{t('admin.settings.openingTime')}</label>
            <input
              id="openingTime"
              type="time"
              value={settings.openingTime}
              onChange={(event) => patch({ openingTime: event.target.value })}
            />
          </div>

          <div className="field">
            <label htmlFor="closingTime">{t('admin.settings.closingTime')}</label>
            <input
              id="closingTime"
              type="time"
              value={settings.closingTime}
              onChange={(event) => patch({ closingTime: event.target.value })}
            />
          </div>

          <div className="field">
            <label htmlFor="timezone">{t('admin.settings.timezone')}</label>
            <input
              id="timezone"
              value={settings.timezone}
              onChange={(event) => patch({ timezone: event.target.value })}
              placeholder="Europe/Warsaw"
            />
          </div>

          <div className="field">
            <label htmlFor="defaultLocale">{t('admin.settings.defaultLocale')}</label>
            <select
              id="defaultLocale"
              value={settings.defaultLocale}
              onChange={(event) => patch({ defaultLocale: event.target.value as Locale })}
            >
              {SUPPORTED_LOCALES.map((locale) => (
                <option key={locale} value={locale}>
                  {LOCALE_LABELS[locale]}
                </option>
              ))}
            </select>
          </div>

          <div className="field">
            <label htmlFor="deltaTolerance">{t('admin.settings.deltaTolerance')}</label>
            <input
              id="deltaTolerance"
              type="number"
              min="0"
              step="0.01"
              value={settings.deltaTolerance}
              onChange={(event) => patch({ deltaTolerance: Number(event.target.value) })}
            />
            <span className="muted">{t('admin.settings.deltaToleranceHint')}</span>
          </div>
        </div>

        <div className="checkbox-row">
          <input
            id="photoRequired"
            type="checkbox"
            checked={settings.photoRequired}
            onChange={(event) => patch({ photoRequired: event.target.checked })}
          />
          <label htmlFor="photoRequired">{t('admin.settings.photoRequired')}</label>
        </div>

        <div className="checkbox-row">
          <input
            id="photoPerProduct"
            type="checkbox"
            checked={settings.photoPerProduct}
            disabled={!settings.photoRequired}
            onChange={(event) => patch({ photoPerProduct: event.target.checked })}
          />
          <label htmlFor="photoPerProduct">{t('admin.settings.photoPerProduct')}</label>
        </div>
        <p className="muted">{t('admin.settings.photoPerProductHint')}</p>

        <div className="row" style={{ marginTop: '1rem' }}>
          <div className="spacer" />
          <button type="button" onClick={() => void save()} disabled={saving}>
            {saving ? t('common.saving') : t('common.save')}
          </button>
        </div>
      </Card>
    </>
  );
}
