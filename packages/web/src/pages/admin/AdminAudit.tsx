/** §7.9 — Historique / audit des comptages et validations, + déverrouillage admin. */

import { isIsoDate, previousDay, type CountMoment } from '@merisu/domain';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../../api/client.js';
import type { AuditEntry } from '../../api/types.js';
import { Banner, Card, Spinner, formatDateTime } from '../../components/common.js';
import { useApiError } from '../../hooks/useApiError.js';

function defaultPeriod(): { from: string; to: string } {
  const to = new Date().toISOString().slice(0, 10);
  let from = to;
  for (let i = 0; i < 13; i += 1) from = previousDay(from);
  return { from, to };
}

export function AdminAudit() {
  const { t, i18n } = useTranslation();
  const translateError = useApiError();

  const initial = useMemo(defaultPeriod, []);
  const [from, setFrom] = useState(initial.from);
  const [to, setTo] = useState(initial.to);
  const [entries, setEntries] = useState<AuditEntry[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Formulaire de déverrouillage (§5.3 — ADMIN uniquement, avec motif tracé).
  const [unlockDate, setUnlockDate] = useState(initial.to);
  const [unlockMoment, setUnlockMoment] = useState<CountMoment>('CLOSE_2200');
  const [unlockWorkstation, setUnlockWorkstation] = useState('');
  const [unlockReason, setUnlockReason] = useState('');

  const load = useCallback(async () => {
    // Voir AdminDelta : on ignore les périodes incomplètes en cours de saisie.
    if (!isIsoDate(from) || !isIsoDate(to) || from > to) return;

    setLoading(true);
    setError(null);
    try {
      const response = await api.audit({ from, to, limit: 300 });
      setEntries(response.entries);
    } catch (caught) {
      setError(translateError(caught));
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [from, to]);

  useEffect(() => {
    void load();
  }, [load]);

  async function unlock(): Promise<void> {
    setError(null);
    setNotice(null);
    try {
      await api.unlock({
        date: unlockDate,
        moment: unlockMoment,
        workstationId: unlockWorkstation || undefined,
        reason: unlockReason || undefined,
      });
      setNotice(t('admin.audit.unlockDone'));
      setUnlockReason('');
      await load();
    } catch (caught) {
      setError(translateError(caught));
    }
  }

  return (
    <>
      <div className="card-header">
        <div>
          <h1>{t('admin.audit.title')}</h1>
          <p className="muted">{t('admin.audit.subtitle')}</p>
        </div>
      </div>

      {error && <Banner kind="danger">{error}</Banner>}
      {notice && <Banner kind="ok">{notice}</Banner>}

      <Card>
        <h2>{t('admin.audit.unlockTitle')}</h2>
        <div className="field-grid">
          <div className="field">
            <label htmlFor="unlockDate">{t('common.date')}</label>
            <input
              id="unlockDate"
              type="date"
              value={unlockDate}
              onChange={(event) => setUnlockDate(event.target.value)}
            />
          </div>
          <div className="field">
            <label htmlFor="unlockMoment">{t('nav.evening')}</label>
            <select
              id="unlockMoment"
              value={unlockMoment}
              onChange={(event) => setUnlockMoment(event.target.value as CountMoment)}
            >
              <option value="CLOSE_2200">{t('nav.evening')}</option>
              <option value="OPEN_0800">{t('nav.morning')}</option>
            </select>
          </div>
          <div className="field">
            <label htmlFor="unlockWorkstation">{t('common.workstation')}</label>
            <input
              id="unlockWorkstation"
              value={unlockWorkstation}
              onChange={(event) => setUnlockWorkstation(event.target.value)}
              placeholder="ws-1"
            />
          </div>
          <div className="field">
            <label htmlFor="unlockReason">{t('admin.audit.unlockReason')}</label>
            <input
              id="unlockReason"
              value={unlockReason}
              onChange={(event) => setUnlockReason(event.target.value)}
            />
          </div>
        </div>
        <button type="button" className="danger" onClick={() => void unlock()}>
          {t('admin.audit.unlock')}
        </button>
      </Card>

      <Card>
        <div className="row">
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="auditFrom">{t('common.from')}</label>
            <input
              id="auditFrom"
              type="date"
              value={from}
              onChange={(event) => setFrom(event.target.value)}
            />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="auditTo">{t('common.to')}</label>
            <input
              id="auditTo"
              type="date"
              value={to}
              onChange={(event) => setTo(event.target.value)}
            />
          </div>
          <div className="spacer" />
          <button type="button" className="secondary" onClick={() => void load()}>
            {t('common.refresh')}
          </button>
        </div>
      </Card>

      {loading ? (
        <Spinner />
      ) : (
        <Card>
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>{t('admin.audit.when')}</th>
                  <th>{t('admin.audit.actor')}</th>
                  <th>{t('admin.audit.action')}</th>
                  <th>{t('common.workstation')}</th>
                  <th>{t('common.date')}</th>
                  <th>{t('admin.audit.details')}</th>
                </tr>
              </thead>
              <tbody>
                {entries.map((entry) => (
                  <tr key={entry.id}>
                    <td>{formatDateTime(entry.at, i18n.language)}</td>
                    <td>
                      {entry.actorId}{' '}
                      <span className="badge neutral">{entry.actorRole}</span>
                    </td>
                    <td>
                      {t(`admin.actions.${entry.action}`, { defaultValue: entry.action })}
                    </td>
                    <td>{entry.workstationId ?? '—'}</td>
                    <td>{entry.businessDate ?? '—'}</td>
                    <td style={{ whiteSpace: 'normal', maxWidth: 280 }}>
                      <span className="muted">{JSON.stringify(entry.details)}</span>
                    </td>
                  </tr>
                ))}
                {entries.length === 0 && (
                  <tr>
                    <td colSpan={6} className="partial">
                      {t('admin.audit.empty')}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </>
  );
}
