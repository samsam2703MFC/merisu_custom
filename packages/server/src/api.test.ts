/**
 * Tests d'intégration de l'API : parcours consultant complet et règles d'accès.
 * Le store mémoire permet de les exécuter sans base de données.
 */

import request from 'supertest';
import { beforeEach, describe, expect, it } from 'vitest';

import { createTestHarness, login, type TestHarness } from './testHarness.js';

// 2026-07-31 est un vendredi → le plan du soir vise le samedi 2026-08-01.
const TODAY = '2026-07-31';
const TOMORROW = '2026-08-01';

let harness: TestHarness;
let consultantToken: string;
let adminToken: string;

beforeEach(async () => {
  harness = await createTestHarness({
    seedProducts: 3,
    parMatrix: [
      ['product-1', 'SAT', 30],
      ['product-2', 'SAT', 20],
      // product-3 volontairement sans seuil → avertissement admin attendu.
    ],
  });
  consultantToken = await login(harness.app, 'consultant1', '1111', 'ws-1');
  adminToken = await login(harness.app, 'admin', '0000', 'ws-1');

  // Photo non exigée par défaut dans ces tests : la politique photo est testée à part.
  await request(harness.app)
    .put('/api/admin/settings')
    .set('Authorization', `Bearer ${adminToken}`)
    .send({ photoRequired: false });
});

const auth = (token: string) => ({ Authorization: `Bearer ${token}` });

async function saveCounts(
  token: string,
  moment: 'OPEN_0800' | 'CLOSE_2200',
  entries: Array<{ productId: string; qty: number }>,
  date = TODAY,
) {
  return request(harness.app)
    .put('/api/counts')
    .set(auth(token))
    .send({ date, workstationId: 'ws-1', moment, entries });
}

describe('authentification', () => {
  it('refuse un mauvais code', async () => {
    const response = await request(harness.app)
      .post('/api/auth/login')
      .send({ login: 'consultant1', secret: 'mauvais' });

    expect(response.status).toBe(401);
    expect(response.body.error.code).toBe('INVALID_CREDENTIALS');
  });

  it('refuse une requête sans jeton', async () => {
    const response = await request(harness.app).get('/api/day-sheet');
    expect(response.status).toBe(401);
  });

  it('affecte le poste par défaut du consultant', async () => {
    const response = await request(harness.app)
      .post('/api/auth/login')
      .send({ login: 'consultant2', secret: '2222' });

    expect(response.body.workstationId).toBe('ws-2');
  });
});

describe('§3.1/§3.2 — parcours de saisie', () => {
  it('enregistre puis relit les quantités du matin', async () => {
    const saved = await saveCounts(consultantToken, 'OPEN_0800', [
      { productId: 'product-1', qty: 12 },
      { productId: 'product-2', qty: 5 },
      { productId: 'product-3', qty: 0 },
    ]);
    expect(saved.status).toBe(200);

    const sheet = await request(harness.app)
      .get('/api/day-sheet')
      .query({ date: TODAY, moment: 'OPEN_0800' })
      .set(auth(consultantToken));

    expect(sheet.body.counts).toHaveLength(3);
    expect(sheet.body.validated).toBe(false);
    expect(sheet.body.products).toHaveLength(3);
  });

  it('est idempotent : rejouer un envoi ne crée pas de doublon (file hors-ligne)', async () => {
    const entries = [{ productId: 'product-1', qty: 7 }];
    await saveCounts(consultantToken, 'OPEN_0800', entries);
    await saveCounts(consultantToken, 'OPEN_0800', entries);

    const counts = await harness.store.findCounts({ date: TODAY, moment: 'OPEN_0800' });
    expect(counts).toHaveLength(1);
    expect(counts[0]!.qty).toBe(7);
  });

  it('met à jour la quantité lors d’un second envoi', async () => {
    await saveCounts(consultantToken, 'OPEN_0800', [{ productId: 'product-1', qty: 7 }]);
    await saveCounts(consultantToken, 'OPEN_0800', [{ productId: 'product-1', qty: 9 }]);

    const counts = await harness.store.findCounts({ date: TODAY, moment: 'OPEN_0800' });
    expect(counts[0]!.qty).toBe(9);
  });

  it('refuse une quantité négative', async () => {
    const response = await saveCounts(consultantToken, 'OPEN_0800', [
      { productId: 'product-1', qty: -1 },
    ]);

    expect(response.status).toBe(400);
    expect(response.body.error.code).toBe('INVALID_QTY');
  });

  it('refuse un produit inconnu ou inactif', async () => {
    const response = await saveCounts(consultantToken, 'OPEN_0800', [
      { productId: 'product-inexistant', qty: 3 },
    ]);

    expect(response.status).toBe(400);
    expect(response.body.error.code).toBe('UNKNOWN_OR_INACTIVE_PRODUCT');
  });

  it('calcule l’écart net dès que matin et soir sont saisis', async () => {
    await saveCounts(consultantToken, 'OPEN_0800', [{ productId: 'product-1', qty: 40 }]);
    await saveCounts(consultantToken, 'CLOSE_2200', [{ productId: 'product-1', qty: 25 }]);

    const sheet = await request(harness.app)
      .get('/api/day-sheet')
      .query({ date: TODAY, moment: 'CLOSE_2200' })
      .set(auth(consultantToken));

    const net = sheet.body.dailyNets.find((n: any) => n.productId === 'product-1');
    expect(net.netConsumption).toBe(15);
    expect(net.partial).toBe(false);
  });
});

describe('§5.3 — validation du soir et verrouillage', () => {
  async function fillEvening() {
    await saveCounts(consultantToken, 'CLOSE_2200', [
      { productId: 'product-1', qty: 12 },
      { productId: 'product-2', qty: 25 },
      { productId: 'product-3', qty: 4 },
    ]);
  }

  it('refuse la validation si un produit actif n’est pas saisi', async () => {
    await saveCounts(consultantToken, 'CLOSE_2200', [{ productId: 'product-1', qty: 12 }]);

    const response = await request(harness.app)
      .post('/api/validate')
      .set(auth(consultantToken))
      .send({ date: TODAY, moment: 'CLOSE_2200' });

    expect(response.status).toBe(409);
    expect(response.body.error.code).toBe('VALIDATION_FAILED');
    expect(response.body.error.details).toContainEqual({
      code: 'MISSING_QTY',
      productId: 'product-2',
    });
  });

  it('valide, verrouille et fige le plan du lendemain', async () => {
    await fillEvening();

    const response = await request(harness.app)
      .post('/api/validate')
      .set(auth(consultantToken))
      .send({ date: TODAY, moment: 'CLOSE_2200' });

    expect(response.status).toBe(200);
    expect(response.body.counts.every((c: any) => c.validated)).toBe(true);

    const plan = response.body.plan;
    // product-1 : requis samedi 30 − clôture 12 = 18
    expect(plan.find((l: any) => l.productId === 'product-1').qtyToProduce).toBe(18);
    // product-2 : clôture 25 > requis 20 → 0
    expect(plan.find((l: any) => l.productId === 'product-2').qtyToProduce).toBe(0);
    // product-3 : aucun seuil défini → 0 + avertissement
    const third = plan.find((l: any) => l.productId === 'product-3');
    expect(third.qtyToProduce).toBe(0);
    expect(third.missingThreshold).toBe(true);
    expect(plan.every((l: any) => l.forDate === TOMORROW)).toBe(true);
    expect(plan.every((l: any) => l.status === 'FROZEN')).toBe(true);
  });

  it('verrouille la saisie du consultant après validation', async () => {
    await fillEvening();
    await request(harness.app)
      .post('/api/validate')
      .set(auth(consultantToken))
      .send({ date: TODAY, moment: 'CLOSE_2200' });

    const response = await saveCounts(consultantToken, 'CLOSE_2200', [
      { productId: 'product-1', qty: 99 },
    ]);

    expect(response.status).toBe(409);
    expect(response.body.error.code).toBe('COUNT_LOCKED');
  });

  it('refuse une double validation', async () => {
    await fillEvening();
    await request(harness.app)
      .post('/api/validate')
      .set(auth(consultantToken))
      .send({ date: TODAY, moment: 'CLOSE_2200' });

    const response = await request(harness.app)
      .post('/api/validate')
      .set(auth(consultantToken))
      .send({ date: TODAY, moment: 'CLOSE_2200' });

    expect(response.status).toBe(409);
    expect(response.body.error.details).toContainEqual({ code: 'ALREADY_VALIDATED' });
  });

  it('laisse l’admin déverrouiller et corriger, en traçant l’opération', async () => {
    await fillEvening();
    await request(harness.app)
      .post('/api/validate')
      .set(auth(consultantToken))
      .send({ date: TODAY, moment: 'CLOSE_2200' });

    const unlock = await request(harness.app)
      .post('/api/unlock')
      .set(auth(adminToken))
      .send({ date: TODAY, moment: 'CLOSE_2200', workstationId: 'ws-1', reason: 'erreur de comptage' });
    expect(unlock.status).toBe(200);

    const corrected = await saveCounts(consultantToken, 'CLOSE_2200', [
      { productId: 'product-1', qty: 20 },
    ]);
    expect(corrected.status).toBe(200);

    const audit = await request(harness.app)
      .get('/api/admin/audit')
      .set(auth(adminToken));
    const actions = audit.body.entries.map((e: any) => e.action);
    expect(actions).toContain('COUNT_UNLOCKED');
    expect(actions).toContain('EVENING_VALIDATED');
    expect(actions).toContain('PRODUCTION_PLAN_FROZEN');
  });

  it('interdit le déverrouillage à un consultant', async () => {
    const response = await request(harness.app)
      .post('/api/unlock')
      .set(auth(consultantToken))
      .send({ date: TODAY, moment: 'CLOSE_2200' });

    expect(response.status).toBe(403);
  });

  it('exige une photo quand l’admin l’impose', async () => {
    await request(harness.app)
      .put('/api/admin/settings')
      .set(auth(adminToken))
      .send({ photoRequired: true, photoPerProduct: false });

    await fillEvening();

    const response = await request(harness.app)
      .post('/api/validate')
      .set(auth(consultantToken))
      .send({ date: TODAY, moment: 'CLOSE_2200' });

    expect(response.status).toBe(409);
    expect(response.body.error.details).toContainEqual({ code: 'MISSING_GLOBAL_PHOTO' });
  });

  it('n’exige pas de photo au comptage du matin', async () => {
    await request(harness.app)
      .put('/api/admin/settings')
      .set(auth(adminToken))
      .send({ photoRequired: true, photoPerProduct: true });

    await saveCounts(consultantToken, 'OPEN_0800', [
      { productId: 'product-1', qty: 1 },
      { productId: 'product-2', qty: 1 },
      { productId: 'product-3', qty: 1 },
    ]);

    const response = await request(harness.app)
      .post('/api/validate')
      .set(auth(consultantToken))
      .send({ date: TODAY, moment: 'OPEN_0800' });

    expect(response.status).toBe(200);
    expect(response.body.plan).toEqual([]);
  });
});

describe('§3.1.3 — le plan figé est relu le lendemain matin', () => {
  it('expose le plan du jour sur la feuille du matin', async () => {
    await saveCounts(consultantToken, 'CLOSE_2200', [
      { productId: 'product-1', qty: 12 },
      { productId: 'product-2', qty: 25 },
      { productId: 'product-3', qty: 4 },
    ]);
    await request(harness.app)
      .post('/api/validate')
      .set(auth(consultantToken))
      .send({ date: TODAY, moment: 'CLOSE_2200' });

    const sheet = await request(harness.app)
      .get('/api/day-sheet')
      .query({ date: TOMORROW, moment: 'OPEN_0800' })
      .set(auth(consultantToken));

    expect(sheet.body.planForToday).toHaveLength(3);
    expect(
      sheet.body.planForToday.find((l: any) => l.productId === 'product-1').qtyToProduce,
    ).toBe(18);
  });

  it('renvoie un plan vide quand le soir précédent n’a pas été validé', async () => {
    const plan = await request(harness.app)
      .get('/api/production-plan')
      .query({ forDate: TOMORROW })
      .set(auth(consultantToken));

    expect(plan.body.lines).toEqual([]);
    expect(plan.body.frozen).toBe(false);
    expect(plan.body.computedFromDate).toBe(TODAY);
  });

  it('propose un aperçu non figé avant validation', async () => {
    await saveCounts(consultantToken, 'CLOSE_2200', [{ productId: 'product-1', qty: 10 }]);

    const preview = await request(harness.app)
      .get('/api/production-plan/preview')
      .query({ date: TODAY })
      .set(auth(consultantToken));

    expect(preview.body.forDate).toBe(TOMORROW);
    expect(preview.body.lines.find((l: any) => l.productId === 'product-1').qtyToProduce).toBe(20);
    // L'aperçu ne doit rien enregistrer.
    const stored = await harness.store.getProductionPlan({
      forDate: TOMORROW,
      workstationId: 'ws-1',
    });
    expect(stored).toEqual([]);
  });
});

describe('cloisonnement par poste et par rôle', () => {
  it('empêche un consultant de saisir sur un autre poste', async () => {
    const response = await request(harness.app)
      .put('/api/counts')
      .set(auth(consultantToken))
      .send({
        date: TODAY,
        workstationId: 'ws-2',
        moment: 'OPEN_0800',
        entries: [{ productId: 'product-1', qty: 3 }],
      });

    expect(response.status).toBe(403);
    expect(response.body.error.code).toBe('WORKSTATION_NOT_ALLOWED');
  });

  it('autorise l’admin à consulter n’importe quel poste', async () => {
    const response = await request(harness.app)
      .get('/api/day-sheet')
      .query({ date: TODAY, moment: 'OPEN_0800', workstationId: 'ws-2' })
      .set(auth(adminToken));

    expect(response.status).toBe(200);
    expect(response.body.workstationId).toBe('ws-2');
  });

  it('interdit les rapports aux consultants', async () => {
    const response = await request(harness.app)
      .get('/api/reports/delta')
      .set(auth(consultantToken));

    expect(response.status).toBe(403);
  });

  it('interdit à un consultant de modifier les paramètres', async () => {
    const response = await request(harness.app)
      .put('/api/admin/settings')
      .set(auth(consultantToken))
      .send({ deltaTolerance: 0.5 });

    expect(response.status).toBe(403);
  });
});
