import { describe, expect, it } from 'vitest';

import { addDays, businessToday, dateRange, dayOfWeekOf, isIsoDate, nextDay, previousDay } from './date.js';

describe('jour de la semaine', () => {
  it('démarre la semaine au lundi', () => {
    expect(dayOfWeekOf('2026-08-03')).toBe('MON');
    expect(dayOfWeekOf('2026-08-02')).toBe('SUN');
    expect(dayOfWeekOf('2026-07-31')).toBe('FRI');
  });
});

describe('jour suivant / précédent', () => {
  it('gère le passage de semaine dimanche → lundi', () => {
    expect(nextDay('2026-08-02')).toBe('2026-08-03');
  });

  it('gère le passage de mois', () => {
    expect(nextDay('2026-08-31')).toBe('2026-09-01');
  });

  it('gère le passage d’année', () => {
    expect(nextDay('2026-12-31')).toBe('2027-01-01');
    expect(previousDay('2027-01-01')).toBe('2026-12-31');
  });

  it('gère une année bissextile', () => {
    expect(nextDay('2028-02-28')).toBe('2028-02-29');
    expect(nextDay('2028-02-29')).toBe('2028-03-01');
    expect(nextDay('2026-02-28')).toBe('2026-03-01');
  });

  it('décale d’un nombre de jours arbitraire', () => {
    expect(addDays('2026-07-31', 7)).toBe('2026-08-07');
    expect(addDays('2026-07-31', -31)).toBe('2026-06-30');
  });
});

describe('validation et plages de dates', () => {
  it('rejette les dates mal formées ou inexistantes', () => {
    expect(isIsoDate('2026-13-01')).toBe(false);
    expect(isIsoDate('2026-02-30')).toBe(false);
    expect(isIsoDate('31/07/2026')).toBe(false);
    expect(isIsoDate('2026-07-31')).toBe(true);
  });

  it('lève une erreur explicite sur une date invalide', () => {
    expect(() => nextDay('pas-une-date')).toThrow(/Date métier invalide/);
  });

  it('produit une plage inclusive', () => {
    expect(dateRange('2026-07-30', '2026-08-01')).toEqual([
      '2026-07-30',
      '2026-07-31',
      '2026-08-01',
    ]);
  });

  it('renvoie une plage vide si les bornes sont inversées', () => {
    expect(dateRange('2026-08-01', '2026-07-30')).toEqual([]);
  });
});

describe('date métier selon le fuseau', () => {
  it('utilise le fuseau configuré, pas celui du serveur', () => {
    // 2026-07-31T23:30Z est déjà le 1er août à Varsovie (UTC+2 en été).
    const instant = new Date('2026-07-31T23:30:00Z');

    expect(businessToday('Europe/Warsaw', instant)).toBe('2026-08-01');
    expect(businessToday('UTC', instant)).toBe('2026-07-31');
  });

  it('retombe sur UTC si le fuseau est inconnu', () => {
    const instant = new Date('2026-07-31T10:00:00Z');
    expect(businessToday('Zone/Inexistante', instant)).toBe('2026-07-31');
  });
});
