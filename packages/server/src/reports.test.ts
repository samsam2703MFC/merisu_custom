/** Tests des rapports admin (§6) : delta technique, écarts nets, matrice, CSV. */

import request from 'supertest';
import { beforeEach, describe, expect, it } from 'vitest';

import { createTestHarness, login, type TestHarness } from './testHarness.js';

const TODAY = '2026-07-31'; // vendredi
const TOMORROW = '2026-08-01';

let harness: TestHarness;
let adminToken: string;
let consultantToken: string;

const auth = (token: string) => ({ Authorization: `Bearer ${token}` });

beforeEach(async () => {
  harness = await createTestHarness({
    seedProducts: 2,
    parMatrix: [
      ['product-1', 'SAT', 100],
      ['product-2', 'SAT', 50],
    ],
  });
  adminToken = await login(harness.app, 'admin', '0000', 'ws-1');
  consultantToken = await login(harness.app, 'consultant1', '1111', 'ws-1');

  await request(harness.app)
    .put('/api/admin/settings')
    .set(auth(adminToken))
    .send({ photoRequired: false });
});

/** Saisit un matin + un soir et valide, ce qui fige le plan du lendemain. */
async function runFullDay(closing: Record<string, number>, opening?: Record<string, number>) {
  if (opening) {
    await request(harness.app)
      .put('/api/counts')
      .set(auth(consultantToken))
      .send({
        date: TODAY,
        moment: 'OPEN_0800',
        entries: Object.entries(opening).map(([productId, qty]) => ({ productId, qty })),
      });
  }

  await request(harness.app)
    .put('/api/counts')
    .set(auth(consultantToken))
    .send({
      date: TODAY,
      moment: 'CLOSE_2200',
      entries: Object.entries(closing).map(([productId, qty]) => ({ productId, qty })),
    });

  return request(harness.app)
    .post('/api/validate')
    .set(auth(consultantToken))
    .send({ date: TODAY, moment: 'CLOSE_2200' });
}

describe('§5.1 — rapport des écarts nets', () => {
  it('agrège la consommation nette par produit', async () => {
    await runFullDay({ 'product-1': 20, 'product-2': 5 }, { 'product-1': 50, 'product-2': 30 });

    const report = await request(harness.app)
      .get('/api/reports/daily-net')
      .query({ from: TODAY, to: TODAY })
      .set(auth(adminToken));

    expect(report.body.totalsByProduct['product-1'].total).toBe(30);
    expect(report.body.totalsByProduct['product-2'].total).toBe(25);
    expect(report.body.partial).toBe(false);
  });

  it('signale les journées incomplètes au lieu de les compter comme 0', async () => {
    await runFullDay({ 'product-1': 20, 'product-2': 5 }); // pas de comptage du matin

    const report = await request(harness.app)
      .get('/api/reports/daily-net')
      .query({ from: TODAY, to: TODAY })
      .set(auth(adminToken));

    expect(report.body.partial).toBe(true);
    expect(report.body.missing).toContainEqual({
      date: TODAY,
      productId: 'product-1',
      missing: 'OPEN',
    });
    expect(report.body.totalsByProduct['product-1'].total).toBe(0);
    expect(report.body.totalsByProduct['product-1'].skippedDays).toBe(1);
  });
});

describe('§6 — delta technique', () => {
  /**
   * Après validation, le plan du samedi vaut :
   *   product-1 : 100 − 20 = 80 pièces
   *   product-2 :  50 −  5 = 45 pièces
   * Recettes de démonstration :
   *   product-1 → 0.2 l de lait/pièce, 0.05 kg de sucre/pièce
   *   product-2 → 0.25 l de lait/pièce, 0.04 kg de sucre/pièce
   * Théorique lait = 80×0.2 + 45×0.25 = 27.25 l
   */
  it('calcule la consommation théorique à partir des recettes et du plan figé', async () => {
    await runFullDay({ 'product-1': 20, 'product-2': 5 });

    const report = await request(harness.app)
      .get('/api/reports/delta')
      .query({ from: TOMORROW, to: TOMORROW })
      .set(auth(adminToken));

    expect(report.body.producedByProduct).toEqual({ 'product-1': 80, 'product-2': 45 });

    const milk = report.body.deltas.find((d: any) => d.materialId === 'mat-milk');
    expect(milk.theoreticalQty).toBeCloseTo(27.25, 6);
    // Aucune consommation réelle saisie → rapport partiel, pas de conclusion.
    expect(milk.realQty).toBeNull();
    expect(milk.partial).toBe(true);
    expect(report.body.partial).toBe(true);
  });

  it('confronte le réel au théorique et signale les dépassements de tolérance', async () => {
    await runFullDay({ 'product-1': 20, 'product-2': 5 });

    // Théorique lait = 27.25 ; réel 30 → delta +2.75, soit +10.1 % > 5 %.
    await request(harness.app)
      .post('/api/admin/material-movements')
      .set(auth(adminToken))
      .send({ date: TOMORROW, materialId: 'mat-milk', realQty: 30 });

    const report = await request(harness.app)
      .get('/api/reports/delta')
      .query({ from: TOMORROW, to: TOMORROW })
      .set(auth(adminToken));

    const milk = report.body.deltas.find((d: any) => d.materialId === 'mat-milk');
    expect(milk.realQty).toBe(30);
    expect(milk.delta).toBeCloseTo(2.75, 6);
    expect(milk.deltaPct).toBeCloseTo(0.1009, 3);
    expect(milk.outOfTolerance).toBe(true);
    expect(report.body.summary.outOfTolerance).toBeGreaterThanOrEqual(1);
  });

  it('respecte la tolérance paramétrée en admin', async () => {
    await runFullDay({ 'product-1': 20, 'product-2': 5 });
    await request(harness.app)
      .post('/api/admin/material-movements')
      .set(auth(adminToken))
      .send({ date: TOMORROW, materialId: 'mat-milk', realQty: 30 });

    // Tolérance portée à 15 % : le même écart de 10.1 % rentre dans les clous.
    await request(harness.app)
      .put('/api/admin/settings')
      .set(auth(adminToken))
      .send({ deltaTolerance: 0.15 });

    const report = await request(harness.app)
      .get('/api/reports/delta')
      .query({ from: TOMORROW, to: TOMORROW })
      .set(auth(adminToken));

    const milk = report.body.deltas.find((d: any) => d.materialId === 'mat-milk');
    expect(milk.outOfTolerance).toBe(false);
    expect(report.body.tolerance).toBe(0.15);
  });

  it('marque le rapport partiel quand aucun plan n’existe sur la période', async () => {
    const report = await request(harness.app)
      .get('/api/reports/delta')
      .query({ from: TODAY, to: TODAY })
      .set(auth(adminToken));

    expect(report.body.partial).toBe(true);
    expect(report.body.producedByProduct).toEqual({});
  });

  it('exporte le rapport en CSV avec BOM UTF-8 pour Excel', async () => {
    await runFullDay({ 'product-1': 20, 'product-2': 5 });

    const response = await request(harness.app)
      .get('/api/reports/delta.csv')
      .query({ from: TOMORROW, to: TOMORROW, locale: 'pl' })
      .set(auth(adminToken));

    expect(response.status).toBe(200);
    expect(response.headers['content-type']).toContain('text/csv');
    expect(response.text.charCodeAt(0)).toBe(0xfeff);
    expect(response.text).toContain('material_id,material_name');
    // Libellé de matière résolu dans la langue demandée.
    expect(response.text).toContain('Mleko');
  });
});

describe('§6 — vue matricielle jour × produit', () => {
  it('croise seuil, ouverture, clôture et écart net', async () => {
    await runFullDay({ 'product-1': 20 }, { 'product-1': 50 });

    const report = await request(harness.app)
      .get('/api/reports/matrix')
      .query({ from: TODAY, to: TODAY })
      .set(auth(adminToken));

    const cell = report.body.cells['product-1'][TODAY];
    expect(cell.opening).toBe(50);
    expect(cell.closing).toBe(20);
    expect(cell.net).toBe(30);
    // Vendredi sans seuil défini dans ce jeu de test (seul samedi l'est).
    expect(cell.required).toBeNull();
    expect(report.body.days).toEqual([{ date: TODAY, dayOfWeek: 'FRI' }]);
  });

  it('exporte la matrice en CSV', async () => {
    await runFullDay({ 'product-1': 20 }, { 'product-1': 50 });

    const response = await request(harness.app)
      .get('/api/reports/matrix.csv')
      .query({ from: TODAY, to: TODAY })
      .set(auth(adminToken));

    expect(response.status).toBe(200);
    expect(response.text).toContain('PRODUIT_1');
    expect(response.text).toContain('FRI');
  });
});

describe('administration', () => {
  it('permet de renommer un produit dans les 4 langues', async () => {
    const response = await request(harness.app)
      .put('/api/admin/products/product-1')
      .set(auth(adminToken))
      .send({
        name: { fr: 'Crème vanille', pl: 'Krem waniliowy', it: 'Crema vaniglia', es: 'Crema vainilla' },
        unit: 'pcs',
        wasteFactor: 0.05,
      });

    expect(response.status).toBe(200);
    expect(response.body.product.name.pl).toBe('Krem waniliowy');
    expect(response.body.product.wasteFactor).toBe(0.05);
  });

  it('refuse un facteur de perte négatif', async () => {
    const response = await request(harness.app)
      .put('/api/admin/products/product-1')
      .set(auth(adminToken))
      .send({ wasteFactor: -1 });

    expect(response.status).toBe(400);
    expect(response.body.error.code).toBe('INVALID_WASTE_FACTOR');
  });

  it('désactive un produit, qui disparaît alors de la saisie', async () => {
    await request(harness.app)
      .put('/api/admin/products/product-2')
      .set(auth(adminToken))
      .send({ active: false });

    const sheet = await request(harness.app)
      .get('/api/day-sheet')
      .query({ date: TODAY, moment: 'OPEN_0800' })
      .set(auth(consultantToken));

    expect(sheet.body.products.map((p: any) => p.id)).toEqual(['product-1']);
  });

  it('édite la matrice des seuils et supprime un seuil avec null', async () => {
    await request(harness.app)
      .put('/api/admin/par-matrix')
      .set(auth(adminToken))
      .send({ entries: [{ productId: 'product-1', dayOfWeek: 'MON', requiredPieces: 42 }] });

    let matrix = await request(harness.app).get('/api/admin/par-matrix').set(auth(adminToken));
    expect(
      matrix.body.entries.find((e: any) => e.productId === 'product-1' && e.dayOfWeek === 'MON')
        .requiredPieces,
    ).toBe(42);

    await request(harness.app)
      .put('/api/admin/par-matrix')
      .set(auth(adminToken))
      .send({ entries: [{ productId: 'product-1', dayOfWeek: 'MON', requiredPieces: null }] });

    matrix = await request(harness.app).get('/api/admin/par-matrix').set(auth(adminToken));
    expect(
      matrix.body.entries.find((e: any) => e.productId === 'product-1' && e.dayOfWeek === 'MON'),
    ).toBeUndefined();
  });

  it('rejette un fuseau horaire inconnu', async () => {
    const response = await request(harness.app)
      .put('/api/admin/settings')
      .set(auth(adminToken))
      .send({ timezone: 'Mars/Olympus' });

    expect(response.status).toBe(400);
    expect(response.body.error.code).toBe('INVALID_TIMEZONE');
  });

  it('rejette une langue non supportée', async () => {
    const response = await request(harness.app)
      .put('/api/admin/settings')
      .set(auth(adminToken))
      .send({ defaultLocale: 'de' });

    expect(response.status).toBe(400);
    expect(response.body.error.code).toBe('INVALID_LOCALE');
  });

  it('accepte les 4 langues obligatoires', async () => {
    for (const locale of ['fr', 'pl', 'it', 'es']) {
      const response = await request(harness.app)
        .put('/api/admin/settings')
        .set(auth(adminToken))
        .send({ defaultLocale: locale });

      expect(response.status).toBe(200);
      expect(response.body.settings.defaultLocale).toBe(locale);
    }
  });
});
