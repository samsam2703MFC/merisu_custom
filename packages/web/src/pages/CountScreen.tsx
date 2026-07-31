/**
 * §7.2 / §7.3 — Écrans de saisie matin (08:00) et soir (22:00).
 *
 * Les deux écrans partagent le même composant : seul le `moment` change, plus
 * la photo et la validation verrouillante, propres au comptage du soir.
 */

import type { CountMoment, ProductionPlan, ValidationIssue } from '@merisu/domain';
import { businessToday } from '@merisu/domain';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../api/client.js';
import type { DaySheet } from '../api/types.js';
import {
  Banner,
  Card,
  ConfirmDialog,
  Spinner,
  formatDate,
  formatQty,
  formatTime,
  useDataLabel,
} from '../components/common.js';
import { useApiError } from '../hooks/useApiError.js';
import { useSession } from '../state/session.js';

/** Taille maximale de l'image envoyée : au-delà, la photo est redimensionnée. */
const MAX_PHOTO_EDGE = 1280;
const PHOTO_QUALITY = 0.75;

export function CountScreen({ moment }: { moment: CountMoment }) {
  const { t, i18n } = useTranslation();
  const { settings, workstationId } = useSession();
  const translateError = useApiError();
  const label = useDataLabel();

  const [sheet, setSheet] = useState<DaySheet | null>(null);
  const [quantities, setQuantities] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [validating, setValidating] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [message, setMessage] = useState<{ kind: 'ok' | 'warn'; text: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [issues, setIssues] = useState<ValidationIssue[]>([]);
  /** Plan renvoyé par la validation du soir, affiché sans changer d'écran. */
  const [freshPlan, setFreshPlan] = useState<ProductionPlan[] | null>(null);

  const isEvening = moment === 'CLOSE_2200';
  const today = useMemo(
    () => businessToday(settings?.timezone ?? 'UTC'),
    [settings?.timezone],
  );

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await api.daySheet({ date: today, moment });
      setSheet(data);

      // Les champs sont pré-remplis avec ce qui est déjà en base, pas remis à zéro :
      // le consultant peut reprendre son comptage là où il l'a laissé.
      const next: Record<string, string> = {};
      for (const product of data.products) {
        const existing = data.counts.find((c) => c.productId === product.id);
        next[product.id] = existing ? String(existing.qty) : '';
      }
      setQuantities(next);
    } catch (caught) {
      setError(translateError(caught));
    } finally {
      setLoading(false);
    }
    // `translateError` change à chaque rendu (dépend de `t`) : l'exclure évite une boucle.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [today, moment]);

  useEffect(() => {
    void load();
  }, [load]);

  const locked = sheet?.validated ?? false;

  function setQty(productId: string, value: string): void {
    setQuantities((current) => ({ ...current, [productId]: value }));
    setMessage(null);
  }

  function step(productId: string, delta: number): void {
    const current = Number.parseFloat(quantities[productId] ?? '');
    const base = Number.isFinite(current) ? current : 0;
    setQty(productId, String(Math.max(0, Math.round((base + delta) * 1000) / 1000)));
  }

  /** N'envoie que les produits effectivement saisis (un champ vide n'est pas un 0). */
  function collectEntries(): Array<{ productId: string; qty: number }> {
    const entries: Array<{ productId: string; qty: number }> = [];
    for (const product of sheet?.products ?? []) {
      const raw = quantities[product.id];
      if (raw === undefined || raw.trim() === '') continue;
      const qty = Number.parseFloat(raw);
      if (Number.isFinite(qty) && qty >= 0) entries.push({ productId: product.id, qty });
    }
    return entries;
  }

  async function save(): Promise<boolean> {
    const entries = collectEntries();
    if (entries.length === 0) return true;

    setSaving(true);
    setError(null);
    try {
      const result = await api.saveCounts({ date: today, moment, entries });
      setMessage(
        result.queued
          ? { kind: 'warn', text: t('status.offlineHint') }
          : { kind: 'ok', text: t('common.saved') },
      );
      if (!result.queued) await load();
      return true;
    } catch (caught) {
      setError(translateError(caught));
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function validate(): Promise<void> {
    setValidating(true);
    setError(null);
    setIssues([]);

    try {
      // On enregistre systématiquement avant de valider : le consultant peut avoir
      // modifié un champ sans avoir appuyé sur « Enregistrer ».
      const saved = await save();
      if (!saved) return;

      const result = await api.validate({ date: today, moment });
      if (result.queued) {
        setMessage({ kind: 'warn', text: t('status.offlineHint') });
      } else {
        setMessage({ kind: 'ok', text: t('common.saved') });
        // §3.2.4 — la production à réaliser demain est affichée immédiatement,
        // sans que le consultant ait à changer d'écran.
        setFreshPlan(result.data?.plan ?? []);
        await load();
      }
      setConfirming(false);
    } catch (caught) {
      const details = (caught as { details?: unknown })?.details;
      if (Array.isArray(details)) setIssues(details as ValidationIssue[]);
      setError(translateError(caught));
      setConfirming(false);
    } finally {
      setValidating(false);
    }
  }

  async function attachPhoto(productId: string, file: File): Promise<void> {
    setError(null);
    try {
      // Le comptage doit exister côté serveur pour porter la photo : on enregistre
      // d'abord. Hors-ligne, la file conserve cet ordre au moment du rejeu.
      await save();

      const { dataBase64, contentType } = await downscaleImage(file);
      const result = await api.addPhotoByKey({
        date: today,
        moment,
        productId,
        dataBase64,
        contentType,
        takenAt: new Date().toISOString(),
      });

      if (result.queued) setMessage({ kind: 'warn', text: t('status.offlineHint') });
      else await load();
    } catch (caught) {
      setError(translateError(caught));
    }
  }

  if (loading && !sheet) return <Spinner />;

  if (error && !sheet) {
    return (
      <Card>
        <Banner kind="danger">{error}</Banner>
        <button type="button" onClick={() => void load()}>
          {t('common.retry')}
        </button>
      </Card>
    );
  }

  if (!sheet) return null;

  const issueByProduct = new Map<string, ValidationIssue[]>();
  for (const issue of issues) {
    if (!issue.productId) continue;
    issueByProduct.set(issue.productId, [...(issueByProduct.get(issue.productId) ?? []), issue]);
  }
  const globalIssues = issues.filter((issue) => !issue.productId);

  const time = isEvening ? sheet.settings.closingTime : sheet.settings.openingTime;

  return (
    <>
      <div className="card-header">
        <div>
          <h1>{isEvening ? t('evening.title') : t('morning.title')}</h1>
          <p className="muted">
            {isEvening
              ? t('evening.subtitle', { time, date: formatDate(sheet.date, i18n.language) })
              : t('morning.subtitle', { time, date: formatDate(sheet.date, i18n.language) })}
          </p>
        </div>
        {workstationId && <span className="badge neutral">{workstationId}</span>}
      </div>

      {error && <Banner kind="danger">{error}</Banner>}
      {message && <Banner kind={message.kind === 'ok' ? 'ok' : 'warn'}>{message.text}</Banner>}

      {globalIssues.map((issue) => (
        <Banner kind="danger" key={issue.code}>
          {t(`validation.${issue.code}`)}
        </Banner>
      ))}

      {locked && (
        <Banner kind="ok">
          {isEvening
            ? t('evening.validated', { time: formatTime(sheet.validatedAt, i18n.language) })
            : t('morning.validated', { time: formatTime(sheet.validatedAt, i18n.language) })}
          <br />
          <span className="muted">{t('evening.lockedHint')}</span>
        </Banner>
      )}

      {/* Le matin, rappel du plan calculé la veille (§3.1.3). */}
      {!isEvening && (
        <Card>
          <h2>{t('morning.planTitle')}</h2>
          {sheet.planForToday.length === 0 ? (
            <p className="muted">{t('morning.planEmpty')}</p>
          ) : (
            <>
              <p className="muted">{t('morning.planHint')}</p>
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr>
                      <th>{t('common.product')}</th>
                      <th>{t('produce.qtyToProduce')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sheet.planForToday.map((line) => {
                      const product = sheet.products.find((p) => p.id === line.productId);
                      return (
                        <tr key={line.id}>
                          <td>{label(product?.name, line.productId)}</td>
                          <td>
                            <strong>{formatQty(line.qtyToProduce, i18n.language)}</strong>{' '}
                            {product?.unit}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </Card>
      )}

      {/* §3.2.4 — production à réaliser demain, affichée juste après la validation. */}
      {isEvening && freshPlan && freshPlan.length > 0 && (
        <Card>
          <h2>{t('produce.title')}</h2>
          <p className="muted">
            {t('produce.subtitle', {
              date: formatDate(freshPlan[0]!.forDate, i18n.language),
            })}
          </p>
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>{t('common.product')}</th>
                  <th>{t('produce.required')}</th>
                  <th>{t('produce.closingStock')}</th>
                  <th>{t('produce.qtyToProduce')}</th>
                </tr>
              </thead>
              <tbody>
                {freshPlan.map((line) => {
                  const product = sheet.products.find((p) => p.id === line.productId);
                  return (
                    <tr key={line.id}>
                      <td>
                        {label(product?.name, line.productId)}
                        {line.missingThreshold && (
                          <> <span className="badge warn">{t('common.notDefined')}</span></>
                        )}
                      </td>
                      <td>{formatQty(line.requiredPieces, i18n.language)}</td>
                      <td>{formatQty(line.closingStock, i18n.language)}</td>
                      <td>
                        <strong>{formatQty(line.qtyToProduce, i18n.language)}</strong>{' '}
                        {product?.unit}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {isEvening && sheet.settings.photoRequired && (
        <Banner kind="info">
          <strong>{t('evening.photoRequired')}</strong>{' '}
          {sheet.settings.photoPerProduct ? t('evening.photoPerProduct') : t('evening.photoGlobal')}
        </Banner>
      )}

      <div className="count-list">
        {sheet.products.map((product) => {
          const count = sheet.counts.find((c) => c.productId === product.id);
          const productIssues = issueByProduct.get(product.id) ?? [];
          const net = sheet.dailyNets.find((n) => n.productId === product.id);

          return (
            <div
              key={product.id}
              className={`count-item ${productIssues.length > 0 ? 'invalid' : ''}`}
            >
              <div className="row">
                <span className="name">{label(product.name, product.code)}</span>
                <div className="spacer" />
                <span className="muted">{product.unit}</span>
              </div>

              <div className="count-controls">
                <button
                  type="button"
                  onClick={() => step(product.id, -1)}
                  disabled={locked}
                  aria-label={`− ${label(product.name, product.code)}`}
                >
                  −
                </button>
                <input
                  type="number"
                  inputMode="decimal"
                  min="0"
                  step="any"
                  value={quantities[product.id] ?? ''}
                  onChange={(event) => setQty(product.id, event.target.value)}
                  disabled={locked}
                  aria-label={`${t('common.quantity')} ${label(product.name, product.code)}`}
                />
                <button
                  type="button"
                  onClick={() => step(product.id, 1)}
                  disabled={locked}
                  aria-label={`+ ${label(product.name, product.code)}`}
                >
                  +
                </button>
              </div>

              {isEvening && net && net.netConsumption !== null && (
                <p className="muted" style={{ margin: 0 }}>
                  {t('evening.netConsumption')} : {formatQty(net.netConsumption, i18n.language)}{' '}
                  {product.unit}
                </p>
              )}

              {productIssues.map((issue) => (
                <p key={issue.code} className="badge danger">
                  {t(`validation.${issue.code}`)}
                </p>
              ))}

              {isEvening && (
                <div className="row">
                  <label
                    className="button secondary"
                    style={{ margin: 0, display: 'inline-flex', alignItems: 'center' }}
                  >
                    {t('evening.addPhoto')}
                    <input
                      className="photo-input"
                      type="file"
                      accept="image/*"
                      capture="environment"
                      disabled={locked}
                      onChange={(event) => {
                        const file = event.target.files?.[0];
                        if (file) void attachPhoto(product.id, file);
                        event.target.value = '';
                      }}
                    />
                  </label>

                  {count && count.photos.length > 0 && (
                    <span className="badge ok">
                      {t('evening.photos', { count: count.photos.length })}
                    </span>
                  )}
                </div>
              )}

              {count && count.photos.length > 0 && (
                <div className="photo-strip">
                  {count.photos.map((photo) => (
                    <img key={photo.id} src={photo.url} alt="" loading="lazy" />
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>

      <div className="row" style={{ marginTop: '1rem' }}>
        <button
          type="button"
          className="secondary"
          onClick={() => void save()}
          disabled={locked || saving}
        >
          {saving ? t('common.saving') : t('common.save')}
        </button>
        <div className="spacer" />
        <button
          type="button"
          className="large"
          onClick={() => (isEvening ? setConfirming(true) : void validate())}
          disabled={locked || validating}
        >
          {isEvening ? t('evening.validate') : t('morning.validate')}
        </button>
      </div>

      {confirming && (
        <ConfirmDialog
          title={t('evening.confirmTitle')}
          body={t('evening.confirmBody')}
          confirmLabel={t('evening.validate')}
          busy={validating}
          onConfirm={() => void validate()}
          onCancel={() => setConfirming(false)}
        />
      )}
    </>
  );
}

/**
 * Réduit la photo avant envoi.
 *
 * Indispensable au poste : une photo brute de téléphone dépasse 4 Mo, ce qui
 * sature la file hors-ligne et rend l'envoi impossible en réseau faible.
 */
async function downscaleImage(file: File): Promise<{ dataBase64: string; contentType: string }> {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, MAX_PHOTO_EDGE / Math.max(bitmap.width, bitmap.height));

  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);

  const context = canvas.getContext('2d');
  if (!context) throw new Error('CANVAS_UNAVAILABLE');
  context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close();

  const dataUrl = canvas.toDataURL('image/jpeg', PHOTO_QUALITY);
  return { dataBase64: dataUrl.split(',')[1] ?? '', contentType: 'image/jpeg' };
}

export function MorningCount() {
  return <CountScreen moment="OPEN_0800" />;
}

export function EveningCount() {
  return <CountScreen moment="CLOSE_2200" />;
}
