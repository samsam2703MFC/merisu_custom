import { describe, expect, it } from 'vitest';

import { computeProductionLine, computeProductionPlan, resolveRequiredPieces } from './production.js';
import { makeParEntry, makeProduct } from './testFixtures.js';

describe('§5.2 — quantité à produire pour le lendemain', () => {
  it('produit la différence entre le seuil et le stock de clôture', () => {
    const result = computeProductionLine({
      product: makeProduct(),
      closingStock: 12,
      requiredPieces: 30,
    });

    expect(result.rawQtyToProduce).toBe(18);
    expect(result.qtyToProduce).toBe(18);
    expect(result.missingThreshold).toBe(false);
  });

  it('renvoie 0 quand le stock de clôture dépasse le requis (jamais de négatif)', () => {
    const result = computeProductionLine({
      product: makeProduct(),
      closingStock: 50,
      requiredPieces: 30,
    });

    expect(result.rawQtyToProduce).toBe(0);
    expect(result.qtyToProduce).toBe(0);
  });

  it('renvoie 0 quand le stock de clôture est exactement égal au requis', () => {
    const result = computeProductionLine({
      product: makeProduct(),
      closingStock: 30,
      requiredPieces: 30,
    });

    expect(result.qtyToProduce).toBe(0);
  });

  it('produit tout le requis quand le stock de clôture est à 0', () => {
    const result = computeProductionLine({
      product: makeProduct(),
      closingStock: 0,
      requiredPieces: 30,
    });

    expect(result.qtyToProduce).toBe(30);
  });

  it('applique le facteur de perte puis l’arrondi', () => {
    // 30 − 12 = 18 ; 18 × 1.05 = 18.9 ; arrondi supérieur au pas 1 → 19
    const result = computeProductionLine({
      product: makeProduct({ wasteFactor: 0.05 }),
      closingStock: 12,
      requiredPieces: 30,
    });

    expect(result.rawQtyToProduce).toBe(18);
    expect(result.qtyToProduce).toBe(19);
  });

  it('n’applique pas le facteur de perte à un besoin nul', () => {
    const result = computeProductionLine({
      product: makeProduct({ wasteFactor: 0.5 }),
      closingStock: 100,
      requiredPieces: 30,
    });

    expect(result.qtyToProduce).toBe(0);
  });

  it('respecte un arrondi par lot (pas de 6)', () => {
    // 20 − 3 = 17 → arrondi supérieur au multiple de 6 → 18
    const result = computeProductionLine({
      product: makeProduct({ roundingStep: 6, roundingMode: 'CEIL' }),
      closingStock: 3,
      requiredPieces: 20,
    });

    expect(result.qtyToProduce).toBe(18);
  });

  it('conserve les décimales quand l’arrondi est désactivé (produits au gramme)', () => {
    const result = computeProductionLine({
      product: makeProduct({ unit: 'g', roundingMode: 'NONE', wasteFactor: 0.1 }),
      closingStock: 100.5,
      requiredPieces: 250,
    });

    // (250 − 100.5) × 1.1 = 164.45
    expect(result.qtyToProduce).toBeCloseTo(164.45, 6);
  });

  it('traite un seuil absent comme 0 et lève un avertissement', () => {
    const result = computeProductionLine({
      product: makeProduct(),
      closingStock: 5,
      requiredPieces: null,
    });

    expect(result.qtyToProduce).toBe(0);
    expect(result.missingThreshold).toBe(true);
  });

  it('traite un stock de clôture absent comme 0 tout en le signalant', () => {
    const result = computeProductionLine({
      product: makeProduct(),
      closingStock: null,
      requiredPieces: 40,
    });

    expect(result.qtyToProduce).toBe(40);
    expect(result.missingClosingStock).toBe(true);
  });

  it('ignore un facteur de perte négatif (donnée admin aberrante)', () => {
    const result = computeProductionLine({
      product: makeProduct({ wasteFactor: -0.5 }),
      closingStock: 0,
      requiredPieces: 10,
    });

    expect(result.qtyToProduce).toBe(10);
  });
});

describe('§5.2 — plan complet et passage de semaine', () => {
  const products = [
    makeProduct({ id: 'p1', code: 'PRODUIT_1', sortOrder: 1 }),
    makeProduct({ id: 'p2', code: 'PRODUIT_2', sortOrder: 2, wasteFactor: 0.1 }),
  ];

  it('vise bien le lendemain et son jour de semaine', () => {
    // 2026-07-31 est un vendredi → demain samedi.
    const plan = computeProductionPlan({
      today: '2026-07-31',
      products,
      closingStocks: { p1: 10, p2: 0 },
      parMatrix: [makeParEntry('p1', 'SAT', 25), makeParEntry('p2', 'SAT', 10)],
      workstationId: 'ws1',
    });

    expect(plan.forDate).toBe('2026-08-01');
    expect(plan.forDayOfWeek).toBe('SAT');
    expect(plan.lines[0]!.qtyToProduce).toBe(15);
    // p2 : (10 − 0) × 1.1 = 11
    expect(plan.lines[1]!.qtyToProduce).toBe(11);
  });

  it('gère le passage dimanche → lundi', () => {
    // 2026-08-02 est un dimanche.
    const plan = computeProductionPlan({
      today: '2026-08-02',
      products: [products[0]!],
      closingStocks: { p1: 4 },
      parMatrix: [makeParEntry('p1', 'MON', 20), makeParEntry('p1', 'SUN', 99)],
      workstationId: 'ws1',
    });

    expect(plan.forDayOfWeek).toBe('MON');
    expect(plan.forDate).toBe('2026-08-03');
    // Le seuil du LUNDI (20) doit être utilisé, pas celui du dimanche.
    expect(plan.lines[0]!.qtyToProduce).toBe(16);
  });

  it('gère le passage de mois et d’année', () => {
    const plan = computeProductionPlan({
      today: '2026-12-31',
      products: [products[0]!],
      closingStocks: { p1: 0 },
      parMatrix: [makeParEntry('p1', 'FRI', 7)],
      workstationId: 'ws1',
    });

    // 2026-12-31 est un jeudi → demain vendredi 2027-01-01.
    expect(plan.forDate).toBe('2027-01-01');
    expect(plan.forDayOfWeek).toBe('FRI');
    expect(plan.lines[0]!.qtyToProduce).toBe(7);
  });

  it('remonte la liste des produits sans seuil défini', () => {
    const plan = computeProductionPlan({
      today: '2026-07-31',
      products,
      closingStocks: { p1: 0, p2: 0 },
      parMatrix: [makeParEntry('p1', 'SAT', 5)],
      workstationId: 'ws1',
    });

    expect(plan.warningsMissingThreshold).toEqual(['p2']);
    expect(plan.lines[1]!.qtyToProduce).toBe(0);
  });
});

describe('résolution du seuil applicable', () => {
  const matrix = [
    makeParEntry('p1', 'MON', 20, null),
    makeParEntry('p1', 'MON', 35, 'ws2'),
  ];

  it('utilise le seuil global quand aucun seuil poste n’existe', () => {
    expect(resolveRequiredPieces(matrix, 'p1', 'MON', 'ws1')).toBe(20);
  });

  it('privilégie le seuil du poste quand il existe', () => {
    expect(resolveRequiredPieces(matrix, 'p1', 'MON', 'ws2')).toBe(35);
  });

  it('renvoie null quand rien n’est défini pour ce jour', () => {
    expect(resolveRequiredPieces(matrix, 'p1', 'TUE', 'ws1')).toBeNull();
  });

  it('distingue « seuil absent » (null) de « seuil défini à 0 »', () => {
    const withZero = [makeParEntry('p1', 'TUE', 0, null)];
    expect(resolveRequiredPieces(withZero, 'p1', 'TUE', 'ws1')).toBe(0);
    expect(resolveRequiredPieces([], 'p1', 'TUE', 'ws1')).toBeNull();
  });
});
