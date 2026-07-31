/** §7.1 — Connexion, choix du poste et sélecteur de langue. */

import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../api/client.js';
import { Banner, Card, LanguageSwitcher } from '../components/common.js';
import { useApiError } from '../hooks/useApiError.js';
import { useSession } from '../state/session.js';

export function Login() {
  const { t } = useTranslation();
  const { login } = useSession();
  const translateError = useApiError();

  const [identifier, setIdentifier] = useState('');
  const [secret, setSecret] = useState('');
  const [workstationId, setWorkstationId] = useState('');
  const [workstations, setWorkstations] = useState<Array<{ id: string; name: string }>>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  /**
   * La liste des postes vient du module Consultant existant, via une route
   * accessible avant connexion. En cas d'échec (hors-ligne au premier lancement),
   * on laisse le champ vide : le poste pré-affecté du consultant s'appliquera.
   */
  useEffect(() => {
    api
      .publicWorkstations()
      .then((response) => setWorkstations(response.workstations))
      .catch(() => setWorkstations([]));
  }, []);

  async function handleSubmit(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await login(identifier, secret, workstationId || undefined);
    } catch (caught) {
      setError(translateError(caught));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="login-screen">
      <Card className="login-card">
        <div className="card-header">
          <div>
            <h1>{t('common.appName')}</h1>
            <p className="muted">{t('login.subtitle')}</p>
          </div>
          <LanguageSwitcher />
        </div>

        {error && <Banner kind="danger">{error}</Banner>}

        <form onSubmit={handleSubmit} className="stack">
          <div className="field">
            <label htmlFor="identifier">{t('login.identifier')}</label>
            <input
              id="identifier"
              value={identifier}
              onChange={(event) => setIdentifier(event.target.value)}
              autoComplete="username"
              autoCapitalize="none"
              required
            />
          </div>

          <div className="field">
            <label htmlFor="secret">{t('login.secret')}</label>
            <input
              id="secret"
              type="password"
              inputMode="numeric"
              value={secret}
              onChange={(event) => setSecret(event.target.value)}
              autoComplete="current-password"
              required
            />
          </div>

          <div className="field">
            <label htmlFor="workstation">{t('login.workstation')}</label>
            <select
              id="workstation"
              value={workstationId}
              onChange={(event) => setWorkstationId(event.target.value)}
            >
              <option value="">{t('login.workstationAuto')}</option>
              {workstations.map((workstation) => (
                <option key={workstation.id} value={workstation.id}>
                  {workstation.name}
                </option>
              ))}
            </select>
          </div>

          <button type="submit" className="block large" disabled={busy}>
            {busy ? t('common.loading') : t('login.submit')}
          </button>
        </form>

        <hr style={{ margin: '1rem 0', border: 0, borderTop: '1px solid var(--border)' }} />
        <p className="muted">
          <strong>{t('login.demoTitle')}</strong>
          <br />
          {t('login.demoHint')}
        </p>
      </Card>
    </div>
  );
}
