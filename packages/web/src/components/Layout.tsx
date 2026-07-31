/** Enveloppe applicative : entête, indicateur réseau, navigation par rôle. */

import { useEffect, useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { api } from '../api/client.js';
import { useOfflineQueue } from '../hooks/useOnlineStatus.js';
import { useSession } from '../state/session.js';
import { Banner, LanguageSwitcher } from './common.js';

export function Layout() {
  const { t } = useTranslation();
  const { consultant, workstationId, isAdmin, logout, selectWorkstation } = useSession();
  const queue = useOfflineQueue();
  const [workstations, setWorkstations] = useState<Array<{ id: string; name: string }>>([]);

  // Le consultant peut changer de poste en cours de journée (remplacement, renfort).
  useEffect(() => {
    api
      .publicWorkstations()
      .then((response) => setWorkstations(response.workstations))
      .catch(() => setWorkstations([]));
  }, []);

  return (
    <div className="app">
      <header className="app-header">
        <span className="brand">{t('common.appName')}</span>

        <span className={`connectivity ${queue.online ? '' : 'offline'}`}>
          <span className="dot" aria-hidden="true" />
          {queue.online ? t('status.online') : t('status.offline')}
          {queue.pending > 0 && <> · {t('status.pendingSync', { count: queue.pending })}</>}
        </span>

        <LanguageSwitcher />

        <button type="button" className="ghost" onClick={logout}>
          {t('common.logout')}
        </button>
      </header>

      <nav className="app-nav">
        <NavLink to="/morning">{t('nav.morning')}</NavLink>
        <NavLink to="/evening">{t('nav.evening')}</NavLink>
        <NavLink to="/production">{t('nav.toProduce')}</NavLink>
        {isAdmin && <NavLink to="/admin">{t('nav.admin')}</NavLink>}
      </nav>

      <main className="app-main">
        <div className="row" style={{ marginBottom: '0.75rem' }}>
          <span className="muted">{consultant?.displayName}</span>
          <div className="spacer" />
          <label className="row" style={{ gap: '0.4rem', margin: 0 }}>
            <span className="muted">{t('common.workstation')}</span>
            <select
              aria-label={t('common.workstation')}
              value={workstationId ?? ''}
              onChange={(event) => void selectWorkstation(event.target.value)}
              style={{ width: 'auto', minWidth: 140 }}
            >
              {/* Poste courant absent de la liste (poste désactivé) : on l'affiche
                  quand même pour ne pas donner l'illusion d'un autre poste. */}
              {workstationId && !workstations.some((w) => w.id === workstationId) && (
                <option value={workstationId}>{workstationId}</option>
              )}
              {workstations.map((workstation) => (
                <option key={workstation.id} value={workstation.id}>
                  {workstation.name}
                </option>
              ))}
            </select>
          </label>
        </div>

        {!queue.online && <Banner kind="warn">{t('status.offlineHint')}</Banner>}

        {queue.failed.length > 0 && (
          <Banner kind="danger">
            {t('errors.generic')}
            <ul style={{ margin: '0.5rem 0 0', paddingLeft: '1.2rem' }}>
              {queue.failed.map((request, index) => (
                <li key={`${request.path}-${index}`}>
                  {request.path} — {request.lastError}
                </li>
              ))}
            </ul>
          </Banner>
        )}

        <Outlet />
      </main>
    </div>
  );
}
