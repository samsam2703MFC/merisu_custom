/** §7.6 — Paramètres produits : les 8 emplacements, renommables et réglables. */

import { SUPPORTED_LOCALES, type Locale, type Product, type RoundingMode } from '@merisu/domain';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../../api/client.js';
import { Banner, Card, Spinner } from '../../components/common.js';
import { LOCALE_LABELS } from '../../i18n/index.js';
import { useApiError } from '../../hooks/useApiError.js';

const ROUNDING_MODES: RoundingMode[] = ['CEIL', 'FLOOR', 'NEAREST', 'NONE'];

export function AdminProducts() {
  const { t } = useTranslation();
  const translateError = useApiError();

  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [savingId, setSavingId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState<string | null>(null);

  useEffect(() => {
    api
      .products(false)
      .then((response) => setProducts(response.products))
      .catch((caught) => setError(translateError(caught)))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function patch(id: string, changes: Partial<Product>): void {
    setProducts((current) =>
      current.map((product) => (product.id === id ? { ...product, ...changes } : product)),
    );
    setSaved(null);
  }

  async function save(product: Product): Promise<void> {
    setSavingId(product.id);
    setError(null);
    try {
      const response = await api.updateProduct(product.id, {
        name: product.name,
        unit: product.unit,
        active: product.active,
        wasteFactor: product.wasteFactor,
        roundingStep: product.roundingStep,
        roundingMode: product.roundingMode,
        recipeRef: product.recipeRef,
      });
      setProducts((current) =>
        current.map((p) => (p.id === product.id ? response.product : p)),
      );
      setSaved(product.id);
    } catch (caught) {
      setError(translateError(caught));
    } finally {
      setSavingId(null);
    }
  }

  if (loading) return <Spinner />;

  return (
    <>
      <div className="card-header">
        <div>
          <h1>{t('admin.products.title')}</h1>
          <p className="muted">{t('admin.products.subtitle')}</p>
        </div>
      </div>

      {error && <Banner kind="danger">{error}</Banner>}
      <Banner kind="warn">{t('admin.products.placeholderWarning')}</Banner>

      {products.map((product) => (
        <Card key={product.id}>
          <div className="card-header">
            <h2>
              <span className="badge neutral">{product.code}</span>
            </h2>
            <div className="checkbox-row">
              <input
                id={`active-${product.id}`}
                type="checkbox"
                checked={product.active}
                onChange={(event) => patch(product.id, { active: event.target.checked })}
              />
              <label htmlFor={`active-${product.id}`}>{t('admin.products.active')}</label>
            </div>
          </div>

          {/* Un libellé par langue : ce sont des DONNÉES, pas des chaînes d'interface. */}
          <div className="field-grid">
            {SUPPORTED_LOCALES.map((locale) => (
              <div className="field" key={locale}>
                <label htmlFor={`name-${product.id}-${locale}`}>
                  {t('admin.products.namePerLocale', { locale: LOCALE_LABELS[locale as Locale] })}
                </label>
                <input
                  id={`name-${product.id}-${locale}`}
                  value={product.name[locale] ?? ''}
                  onChange={(event) =>
                    patch(product.id, { name: { ...product.name, [locale]: event.target.value } })
                  }
                />
              </div>
            ))}
          </div>

          <div className="field-grid">
            <div className="field">
              <label htmlFor={`unit-${product.id}`}>{t('admin.products.unit')}</label>
              <input
                id={`unit-${product.id}`}
                value={product.unit}
                onChange={(event) => patch(product.id, { unit: event.target.value })}
              />
            </div>

            <div className="field">
              <label htmlFor={`waste-${product.id}`}>{t('admin.products.wasteFactor')}</label>
              <input
                id={`waste-${product.id}`}
                type="number"
                min="0"
                step="0.01"
                value={product.wasteFactor}
                onChange={(event) =>
                  patch(product.id, { wasteFactor: Number(event.target.value) })
                }
              />
              <span className="muted">{t('admin.products.wasteFactorHint')}</span>
            </div>

            <div className="field">
              <label htmlFor={`step-${product.id}`}>{t('admin.products.roundingStep')}</label>
              <input
                id={`step-${product.id}`}
                type="number"
                min="0.001"
                step="0.001"
                value={product.roundingStep}
                onChange={(event) =>
                  patch(product.id, { roundingStep: Number(event.target.value) })
                }
              />
              <span className="muted">{t('admin.products.roundingStepHint')}</span>
            </div>

            <div className="field">
              <label htmlFor={`mode-${product.id}`}>{t('admin.products.roundingMode')}</label>
              <select
                id={`mode-${product.id}`}
                value={product.roundingMode}
                onChange={(event) =>
                  patch(product.id, { roundingMode: event.target.value as RoundingMode })
                }
              >
                {ROUNDING_MODES.map((mode) => (
                  <option key={mode} value={mode}>
                    {t(`admin.roundingMode.${mode}`)}
                  </option>
                ))}
              </select>
            </div>

            <div className="field">
              <label htmlFor={`recipe-${product.id}`}>{t('admin.products.recipeRef')}</label>
              <input
                id={`recipe-${product.id}`}
                value={product.recipeRef ?? ''}
                onChange={(event) => patch(product.id, { recipeRef: event.target.value || null })}
              />
              <span className="muted">{t('admin.products.recipeRefHint')}</span>
            </div>
          </div>

          <div className="row">
            <button type="button" onClick={() => void save(product)} disabled={savingId === product.id}>
              {savingId === product.id ? t('common.saving') : t('common.save')}
            </button>
            {saved === product.id && <span className="badge ok">{t('common.saved')}</span>}
          </div>
        </Card>
      ))}
    </>
  );
}
