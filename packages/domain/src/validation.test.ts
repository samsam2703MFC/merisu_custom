import { describe, expect, it } from 'vitest';

import { makeProduct } from './testFixtures.js';
import { canEditCount, validateEveningCount } from './validation.js';

const products = [makeProduct({ id: 'p1' }), makeProduct({ id: 'p2' })];

const noPhotoSettings = { photoRequired: false, photoPerProduct: false };

describe('§5.3 — validation du comptage du soir', () => {
  it('valide quand tous les produits actifs sont saisis', () => {
    const result = validateEveningCount({
      activeProducts: products,
      quantities: { p1: 10, p2: 0 },
      photoCountByProduct: {},
      globalPhotoCount: 0,
      settings: noPhotoSettings,
      alreadyValidated: false,
    });

    expect(result.valid).toBe(true);
  });

  it('refuse quand un produit actif n’est pas saisi', () => {
    const result = validateEveningCount({
      activeProducts: products,
      quantities: { p1: 10 },
      photoCountByProduct: {},
      globalPhotoCount: 0,
      settings: noPhotoSettings,
      alreadyValidated: false,
    });

    expect(result.valid).toBe(false);
    expect(result.issues).toContainEqual({ code: 'MISSING_QTY', productId: 'p2' });
  });

  it('accepte un stock à 0 (ce n’est pas une absence de saisie)', () => {
    const result = validateEveningCount({
      activeProducts: [products[0]!],
      quantities: { p1: 0 },
      photoCountByProduct: {},
      globalPhotoCount: 0,
      settings: noPhotoSettings,
      alreadyValidated: false,
    });

    expect(result.valid).toBe(true);
  });

  it('refuse une quantité négative', () => {
    const result = validateEveningCount({
      activeProducts: [products[0]!],
      quantities: { p1: -3 },
      photoCountByProduct: {},
      globalPhotoCount: 0,
      settings: noPhotoSettings,
      alreadyValidated: false,
    });

    expect(result.issues).toContainEqual({ code: 'NEGATIVE_QTY', productId: 'p1' });
  });

  it('exige une photo par produit quand l’admin l’impose', () => {
    const result = validateEveningCount({
      activeProducts: products,
      quantities: { p1: 5, p2: 5 },
      photoCountByProduct: { p1: 1 },
      globalPhotoCount: 0,
      settings: { photoRequired: true, photoPerProduct: true },
      alreadyValidated: false,
    });

    expect(result.valid).toBe(false);
    expect(result.issues).toEqual([{ code: 'MISSING_PHOTO', productId: 'p2' }]);
  });

  it('se contente d’une photo globale quand l’admin le permet', () => {
    const result = validateEveningCount({
      activeProducts: products,
      quantities: { p1: 5, p2: 5 },
      photoCountByProduct: {},
      globalPhotoCount: 1,
      settings: { photoRequired: true, photoPerProduct: false },
      alreadyValidated: false,
    });

    expect(result.valid).toBe(true);
  });

  it('accepte une photo rattachée à un produit comme preuve globale', () => {
    const result = validateEveningCount({
      activeProducts: products,
      quantities: { p1: 5, p2: 5 },
      photoCountByProduct: { p1: 2 },
      globalPhotoCount: 0,
      settings: { photoRequired: true, photoPerProduct: false },
      alreadyValidated: false,
    });

    expect(result.valid).toBe(true);
  });

  it('refuse en l’absence totale de photo quand une preuve est exigée', () => {
    const result = validateEveningCount({
      activeProducts: products,
      quantities: { p1: 5, p2: 5 },
      photoCountByProduct: {},
      globalPhotoCount: 0,
      settings: { photoRequired: true, photoPerProduct: false },
      alreadyValidated: false,
    });

    expect(result.issues).toEqual([{ code: 'MISSING_GLOBAL_PHOTO' }]);
  });

  it('refuse une double validation', () => {
    const result = validateEveningCount({
      activeProducts: [products[0]!],
      quantities: { p1: 5 },
      photoCountByProduct: {},
      globalPhotoCount: 0,
      settings: noPhotoSettings,
      alreadyValidated: true,
    });

    expect(result.issues).toContainEqual({ code: 'ALREADY_VALIDATED' });
  });

  it('ignore les produits inactifs (non passés dans activeProducts)', () => {
    const result = validateEveningCount({
      activeProducts: [products[0]!],
      quantities: { p1: 5 },
      photoCountByProduct: {},
      globalPhotoCount: 0,
      settings: noPhotoSettings,
      alreadyValidated: false,
    });

    expect(result.valid).toBe(true);
  });
});

describe('verrouillage après validation', () => {
  it('laisse modifier tant que ce n’est pas validé', () => {
    expect(canEditCount({ validated: false, role: 'CONSULTANT' })).toBe(true);
  });

  it('verrouille le consultant après validation', () => {
    expect(canEditCount({ validated: true, role: 'CONSULTANT' })).toBe(false);
  });

  it('autorise l’admin à corriger une saisie validée', () => {
    expect(canEditCount({ validated: true, role: 'ADMIN' })).toBe(true);
  });
});
