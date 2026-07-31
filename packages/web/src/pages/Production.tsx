/**
 * §7.4 — Écran « À produire ».
 *
 * Affiche le plan FIGÉ à la validation du soir. Par défaut on regarde demain
 * (juste après la clôture) ; le consultant peut revenir sur aujourd'hui.
 */

import { businessToday, isIsoDate, nextDay, type DayOfWeek } from '@merisu/domain';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../api/client.js';
import type { PlanResponse, Product } from '../api/types.js';
import {
  Banner,
  Card,
  Spinner,
  formatDate,
  formatDateTime,
  formatQty,
  useDataLabel,
} from '../components/common.js';
import { useApiError } from '../hooks/useApiError.js';
import { useSession } from '../state/session.js';

export function Production() {
  const { t, i18n } = useTranslation();
  const { settings } = useSession();
  const translateError = useApiError();
  const label = useDataLabel();

  const today = useMemo(() => businessToday(settings?.timezone ?? 'UTC'), [settings?.timezone]);
  const [forDate, setForDate] = useState(() => nextDay(businessToday(settings?.timezone ?? 'UTC')));
  const [plan, setPlan] = useState<PlanResponse | null>(null);
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    // Date en cours de frappe : on attend une date complète avant d'interroger l'API.
    if (!isIsoDate(forDate)) return;

    setLoading(true);
    setError(null);
    try {
      const [planResponse, productResponse] = await Promise.all([
        api.plan({ forDate }),
        api.products(true),
      ]);
      setPlan(planResponse);
      setProducts(productResponse.products);
    } catch (caught) {
      setError(translateError(caught));
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [forDate]);

  useEffect(() => {
    void load();
  }, [load]);

  const productById = new Map(products.map((product) => [product.id, product]));
  const hasWarnings = plan?.lines.some((line) => line.missingThreshold) ?? false;

  return (
    <>
      <div className="card-header">
        <div>
          <h1>{t('produce.title')}</h1>
          <p className="muted">
            {t('produce.subtitle', { date: formatDate(forDate, i18n.language) })}
            {plan && <> · {t(`days.${plan.dayOfWeek as DayOfWeek}`)}</>}
          </p>
        </div>
      </div>

      <Card>
        <div className="row">
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="forDate">{t('common.date')}</label>
            <input
              id="forDate"
              type="date"
              value={forDate}
              onChange={(event) => setForDate(event.target.value)}
            />
          </div>
          <div className="spacer" />
          <button type="button" className="secondary" onClick={() => setForDate(today)}>
            {t('nav.morning')}
          </button>
          <button type="button" className="secondary" onClick={() => setForDate(nextDay(today))}>
            {t('nav.toProduce')}
          </button>
        </div>
      </Card>

      {error && <Banner kind="danger">{error}</Banner>}
      {loading && <Spinner />}

      {plan && !loading && (
        <>
          {plan.lines.length === 0 ? (
            <Banner kind="warn">{t('produce.empty')}</Banner>
          ) : (
            <>
              <p className="muted">
                {t('produce.computedFrom', {
                  date: formatDate(plan.computedFromDate, i18n.language),
                })}
                {plan.lines[0] && (
                  <>
                    {' · '}
                    {t('produce.frozenAt', {
                      datetime: formatDateTime(plan.lines[0].computedAt, i18n.language),
                    })}
                  </>
                )}
              </p>

              {hasWarnings && <Banner kind="warn">{t('produce.missingThreshold')}</Banner>}

              <div className="count-list">
                {plan.lines.map((line) => {
                  const product = productById.get(line.productId);
                  return (
                    <div className="count-item" key={line.id}>
                      <div className="row">
                        <span className="name">{label(product?.name, line.productId)}</span>
                        <div className="spacer" />
                        {line.missingThreshold && (
                          <span className="badge warn">{t('common.notDefined')}</span>
                        )}
                      </div>

                      <div>
                        <span style={{ fontSize: '2rem', fontWeight: 800 }}>
                          {formatQty(line.qtyToProduce, i18n.language)}
                        </span>{' '}
                        <span className="muted">{product?.unit}</span>
                      </div>

                      <p className="muted" style={{ margin: 0 }}>
                        {t('produce.required')} : {formatQty(line.requiredPieces, i18n.language)} ·{' '}
                        {t('produce.closingStock')} : {formatQty(line.closingStock, i18n.language)}
                      </p>

                      {line.qtyToProduce === 0 && !line.missingThreshold && (
                        <p className="badge ok">{t('produce.nothing')}</p>
                      )}
                    </div>
                  );
                })}
              </div>
            </>
          )}
        </>
      )}
    </>
  );
}
