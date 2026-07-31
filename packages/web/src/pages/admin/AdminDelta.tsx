/**
 * §7.8 — Rapport de delta technique (recettes ↔ consommation réelle).
 *
 * Deux vues : delta par matière, et vue matricielle jour × produit. Les écarts
 * hors tolérance sont mis en évidence, et l'incomplétude des données est TOUJOURS
 * affichée pour ne pas laisser conclure à tort (§6).
 */

import { isIsoDate, previousDay, type Material } from '@merisu/domain';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../../api/client.js';
import type { DeltaReport, MatrixReport, Product } from '../../api/types.js';
import { Banner, Card, Spinner, formatQty, useDataLabel } from '../../components/common.js';
import { useApiError } from '../../hooks/useApiError.js';

function defaultPeriod(): { from: string; to: string } {
  const to = new Date().toISOString().slice(0, 10);
  let from = to;
  for (let i = 0; i < 6; i += 1) from = previousDay(from);
  return { from, to };
}

export function AdminDelta() {
  const { t, i18n } = useTranslation();
  const translateError = useApiError();
  const label = useDataLabel();

  const initial = useMemo(defaultPeriod, []);
  const [from, setFrom] = useState(initial.from);
  const [to, setTo] = useState(initial.to);
  const [report, setReport] = useState<DeltaReport | null>(null);
  const [matrix, setMatrix] = useState<MatrixReport | null>(null);
  const [materials, setMaterials] = useState<Material[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    // Une date en cours de frappe est incomplète : on n'interroge l'API que sur
    // une période valide, sinon chaque frappe déclenche une requête rejetée.
    if (!isIsoDate(from) || !isIsoDate(to) || from > to) return;

    setLoading(true);
    setError(null);
    try {
      const [deltaResponse, matrixResponse, materialResponse, productResponse] = await Promise.all([
        api.deltaReport({ from, to }),
        api.matrixReport({ from, to }),
        api.materials(),
        api.products(true),
      ]);
      setReport(deltaResponse);
      setMatrix(matrixResponse);
      setMaterials(materialResponse.materials);
      setProducts(productResponse.products);
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

  const materialById = new Map(materials.map((material) => [material.id, material]));
  const productById = new Map(products.map((product) => [product.id, product]));

  async function download(report_: 'delta' | 'matrix'): Promise<void> {
    try {
      await api.downloadCsv(
        report_,
        { from, to, locale: i18n.language.split('-')[0] },
        `merisu-${report_}-${from}_${to}.csv`,
      );
    } catch (caught) {
      setError(translateError(caught));
    }
  }

  return (
    <>
      <div className="card-header">
        <div>
          <h1>{t('admin.delta.title')}</h1>
          <p className="muted">{t('admin.delta.subtitle')}</p>
        </div>
      </div>

      <Card>
        <div className="row">
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="from">{t('common.from')}</label>
            <input id="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="to">{t('common.to')}</label>
            <input id="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
          <div className="spacer" />
          <button type="button" className="secondary" onClick={() => void load()}>
            {t('common.refresh')}
          </button>
        </div>
      </Card>

      {error && <Banner kind="danger">{error}</Banner>}
      {loading && <Spinner />}

      {report && !loading && (
        <>
          {/* Incomplétude : affichée avant les chiffres, jamais après. */}
          {report.partial && (
            <Banner kind="warn">
              <strong>{t('status.partialData')}</strong> {t('status.partialDataHint')}
              <ul style={{ margin: '0.5rem 0 0', paddingLeft: '1.2rem' }}>
                {Object.keys(report.producedByProduct).length === 0 && (
                  <li>{t('admin.delta.noPlan')}</li>
                )}
                {report.productsWithoutRecipe.length > 0 && (
                  <li>{t('admin.delta.productsWithoutRecipe')}</li>
                )}
                {report.materialsWithoutRealData.length > 0 && (
                  <li>
                    {t('admin.delta.noRealData')} :{' '}
                    {report.materialsWithoutRealData
                      .map((id) => label(materialById.get(id)?.name, id))
                      .join(', ')}
                  </li>
                )}
              </ul>
            </Banner>
          )}

          <Card>
            <div className="card-header">
              <div>
                <h2>{t('admin.delta.title')}</h2>
                <p className="muted">
                  {t('admin.delta.summary', {
                    materials: report.summary.materials,
                    outOfTolerance: report.summary.outOfTolerance,
                  })}{' '}
                  · {t('admin.delta.tolerance', { value: (report.tolerance * 100).toFixed(1) })}
                </p>
              </div>
              <button type="button" className="secondary" onClick={() => void download('delta')}>
                {t('common.export')}
              </button>
            </div>

            <div className="table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>{t('admin.delta.material')}</th>
                    <th>{t('common.unit')}</th>
                    <th>{t('admin.delta.theoretical')}</th>
                    <th>{t('admin.delta.real')}</th>
                    <th>{t('admin.delta.delta')}</th>
                    <th>{t('admin.delta.deltaPct')}</th>
                  </tr>
                </thead>
                <tbody>
                  {report.deltas.map((delta) => {
                    const material = materialById.get(delta.materialId);
                    return (
                      <tr key={delta.materialId}>
                        <td>{label(material?.name, delta.materialId)}</td>
                        <td>{material?.unit ?? '—'}</td>
                        <td>{formatQty(delta.theoreticalQty, i18n.language)}</td>
                        <td className={delta.partial ? 'partial' : ''}>
                          {delta.partial
                            ? t('admin.delta.noRealData')
                            : formatQty(delta.realQty, i18n.language)}
                        </td>
                        <td className={delta.outOfTolerance ? 'out-of-tolerance' : ''}>
                          {formatQty(delta.delta, i18n.language)}
                        </td>
                        <td className={delta.outOfTolerance ? 'out-of-tolerance' : ''}>
                          {delta.deltaPct === null
                            ? '—'
                            : `${(delta.deltaPct * 100).toFixed(1)} %`}
                        </td>
                      </tr>
                    );
                  })}
                  {report.deltas.length === 0 && (
                    <tr>
                      <td colSpan={6} className="partial">
                        {t('admin.delta.noPlan')}
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}

      {matrix && !loading && (
        <Card>
          <div className="card-header">
            <h2>{t('admin.delta.matrixTitle')}</h2>
            <button type="button" className="secondary" onClick={() => void download('matrix')}>
              {t('common.export')}
            </button>
          </div>

          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>{t('common.product')}</th>
                  {matrix.days.map((day) => (
                    <th key={day.date}>
                      {t(`daysShort.${day.dayOfWeek}`)}
                      <br />
                      <span className="muted">{day.date.slice(5)}</span>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {matrix.products.map((product) => (
                  <tr key={product.id}>
                    <td>{label(productById.get(product.id)?.name, product.code)}</td>
                    {matrix.days.map((day) => {
                      const cell = matrix.cells[product.id]?.[day.date];
                      return (
                        <td key={day.date} className={cell?.net === null ? 'partial' : ''}>
                          {cell ? (
                            <>
                              <strong>{formatQty(cell.net, i18n.language)}</strong>
                              <br />
                              <span className="muted" style={{ fontSize: '0.75rem' }}>
                                {formatQty(cell.opening, i18n.language)} →{' '}
                                {formatQty(cell.closing, i18n.language)}
                              </span>
                            </>
                          ) : (
                            '—'
                          )}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="muted">
            {t('admin.delta.net')} · {t('admin.delta.opening')} → {t('admin.delta.closing')}
          </p>
        </Card>
      )}
    </>
  );
}
