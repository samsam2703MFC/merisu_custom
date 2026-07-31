/**
 * §7.5 — Matrice des seuils, éditable (jour × produit).
 *
 * L'orientation du tableau est commutable : produits en lignes (pratique sur
 * desktop) ou jours en lignes (plus lisible sur mobile).
 */

import { DAYS_OF_WEEK, type DayOfWeek, type ParMatrixEntry, type Product } from '@merisu/domain';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../../api/client.js';
import { Banner, Card, Spinner, useDataLabel } from '../../components/common.js';
import { useApiError } from '../../hooks/useApiError.js';

/** Clé de cellule : un seul seuil par (produit, jour) — seuils globaux ici. */
const key = (productId: string, day: DayOfWeek): string => `${productId}|${day}`;

export function AdminParMatrix() {
  const { t } = useTranslation();
  const translateError = useApiError();
  const label = useDataLabel();

  const [products, setProducts] = useState<Product[]>([]);
  const [values, setValues] = useState<Record<string, string>>({});
  const [initial, setInitial] = useState<Record<string, string>>({});
  const [productsInRows, setProductsInRows] = useState(true);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    Promise.all([api.products(false), api.parMatrix()])
      .then(([productResponse, matrixResponse]) => {
        setProducts(productResponse.products);

        const next: Record<string, string> = {};
        for (const entry of matrixResponse.entries as ParMatrixEntry[]) {
          // Les seuils par poste ne sont pas édités ici (question ouverte §10) :
          // cet écran gère les seuils globaux.
          if (entry.workstationId !== null) continue;
          next[key(entry.productId, entry.dayOfWeek)] = String(entry.requiredPieces);
        }
        setValues(next);
        setInitial(next);
      })
      .catch((caught) => setError(translateError(caught)))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const dirty = Object.keys({ ...values, ...initial }).some(
    (cell) => (values[cell] ?? '') !== (initial[cell] ?? ''),
  );

  function setCell(productId: string, day: DayOfWeek, raw: string): void {
    setValues((current) => ({ ...current, [key(productId, day)]: raw }));
    setSaved(false);
  }

  async function save(): Promise<void> {
    setSaving(true);
    setError(null);

    // On n'envoie que les cellules modifiées ; une cellule vidée devient `null`,
    // ce qui SUPPRIME le seuil (≠ seuil à 0, qui reste un choix explicite).
    const entries: Array<{
      productId: string;
      dayOfWeek: DayOfWeek;
      requiredPieces: number | null;
    }> = [];

    for (const cell of new Set([...Object.keys(values), ...Object.keys(initial)])) {
      const current = values[cell] ?? '';
      if (current === (initial[cell] ?? '')) continue;

      const [productId, day] = cell.split('|') as [string, DayOfWeek];
      const parsed = current.trim() === '' ? null : Number(current);
      if (parsed !== null && (!Number.isFinite(parsed) || parsed < 0)) {
        setError(t('errors.INVALID_REQUIRED_PIECES'));
        setSaving(false);
        return;
      }
      entries.push({ productId, dayOfWeek: day, requiredPieces: parsed });
    }

    try {
      await api.updateParMatrix(entries);
      setInitial(values);
      setSaved(true);
    } catch (caught) {
      setError(translateError(caught));
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <Spinner />;

  const rows = productsInRows ? products : DAYS_OF_WEEK;
  const columns = productsInRows ? DAYS_OF_WEEK : products;

  return (
    <>
      <div className="card-header">
        <div>
          <h1>{t('admin.parMatrix.title')}</h1>
          <p className="muted">{t('admin.parMatrix.subtitle')}</p>
        </div>
        <button type="button" className="secondary" onClick={() => setProductsInRows((v) => !v)}>
          {productsInRows ? t('admin.parMatrix.daysInRows') : t('admin.parMatrix.productsInRows')}
        </button>
      </div>

      {error && <Banner kind="danger">{error}</Banner>}
      {saved && <Banner kind="ok">{t('common.saved')}</Banner>}
      <Banner kind="info">{t('admin.parMatrix.hint')}</Banner>

      <Card>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>{productsInRows ? t('common.product') : t('common.date')}</th>
                {columns.map((column) => (
                  <th key={typeof column === 'string' ? column : column.id}>
                    {typeof column === 'string'
                      ? t(`daysShort.${column}`)
                      : label(column.name, column.code)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={typeof row === 'string' ? row : row.id}>
                  <td>
                    {typeof row === 'string' ? (
                      t(`days.${row}`)
                    ) : (
                      <>
                        {label(row.name, row.code)}
                        {!row.active && (
                          <>
                            {' '}
                            <span className="badge warn">{t('admin.products.active')} : {t('common.no')}</span>
                          </>
                        )}
                      </>
                    )}
                  </td>

                  {columns.map((column) => {
                    const product = typeof row === 'string' ? (column as Product) : row;
                    const day = typeof row === 'string' ? row : (column as DayOfWeek);
                    const cellKey = key(product.id, day);

                    return (
                      <td className="editable" key={cellKey}>
                        <input
                          type="number"
                          min="0"
                          step="any"
                          inputMode="numeric"
                          value={values[cellKey] ?? ''}
                          onChange={(event) => setCell(product.id, day, event.target.value)}
                          aria-label={`${label(product.name, product.code)} — ${t(`days.${day}`)}`}
                        />
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="row" style={{ marginTop: '1rem' }}>
          {dirty && <span className="badge warn">{t('admin.parMatrix.unsaved')}</span>}
          <div className="spacer" />
          <button type="button" onClick={() => void save()} disabled={saving || !dirty}>
            {saving ? t('common.saving') : t('common.save')}
          </button>
        </div>
      </Card>
    </>
  );
}
