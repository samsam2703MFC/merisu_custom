import { describe, expect, it } from 'vitest';

import { computeDailyNet, sumNetConsumption } from './netVariance.js';

const base = { date: '2026-07-31', productId: 'p1', workstationId: 'ws1' };

describe('§5.1 — écart net du jour', () => {
  it('applique ouverture + production − clôture', () => {
    const net = computeDailyNet({ ...base, openingStock: 40, producedQty: 60, closingStock: 25 });

    expect(net.netConsumption).toBe(75);
    expect(net.partial).toBe(false);
  });

  it('retombe sur ouverture − clôture quand la production n’est pas suivie', () => {
    const net = computeDailyNet({ ...base, openingStock: 40, closingStock: 25 });

    expect(net.netConsumption).toBe(15);
    expect(net.producedQty).toBe(0);
  });

  it('accepte un écart nul (rien écoulé)', () => {
    const net = computeDailyNet({ ...base, openingStock: 30, closingStock: 30 });

    expect(net.netConsumption).toBe(0);
    expect(net.partial).toBe(false);
  });

  it('renvoie un écart négatif si la clôture dépasse l’entrée (réappro non déclaré)', () => {
    // Cas anormal mais réel : on le remonte tel quel, l'admin doit le voir.
    const net = computeDailyNet({ ...base, openingStock: 10, producedQty: 0, closingStock: 25 });

    expect(net.netConsumption).toBe(-15);
  });

  it('marque « partiel » quand le comptage du matin manque', () => {
    const net = computeDailyNet({ ...base, openingStock: null, closingStock: 25 });

    expect(net.netConsumption).toBeNull();
    expect(net.partial).toBe(true);
  });

  it('marque « partiel » quand le comptage du soir manque', () => {
    const net = computeDailyNet({ ...base, openingStock: 40, closingStock: null });

    expect(net.netConsumption).toBeNull();
    expect(net.partial).toBe(true);
  });

  it('gère les quantités décimales sans artefact flottant', () => {
    const net = computeDailyNet({ ...base, openingStock: 0.3, producedQty: 0.1, closingStock: 0.2 });

    expect(net.netConsumption).toBe(0.2);
  });
});

describe('cumul des écarts nets sur une période', () => {
  it('somme les jours complets et compte les jours ignorés', () => {
    const nets = [
      computeDailyNet({ ...base, openingStock: 40, closingStock: 25 }),
      computeDailyNet({ ...base, date: '2026-08-01', openingStock: 30, closingStock: 10 }),
      computeDailyNet({ ...base, date: '2026-08-02', openingStock: null, closingStock: 10 }),
    ];

    const total = sumNetConsumption(nets);

    expect(total.total).toBe(35);
    expect(total.skipped).toBe(1);
    expect(total.partial).toBe(true);
  });

  it('ne signale rien de partiel quand tout est saisi', () => {
    const nets = [computeDailyNet({ ...base, openingStock: 5, closingStock: 1 })];

    expect(sumNetConsumption(nets)).toEqual({ total: 4, skipped: 0, partial: false });
  });
});
