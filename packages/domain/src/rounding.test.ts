import { describe, expect, it } from 'vitest';

import { roundQuantity } from './rounding.js';

describe('arrondi paramétrable', () => {
  it('arrondit au multiple supérieur (défaut métier)', () => {
    expect(roundQuantity(18.1, { step: 1, mode: 'CEIL' })).toBe(19);
    expect(roundQuantity(18, { step: 1, mode: 'CEIL' })).toBe(18);
  });

  it('arrondit au multiple inférieur', () => {
    expect(roundQuantity(18.9, { step: 1, mode: 'FLOOR' })).toBe(18);
  });

  it('arrondit au plus proche', () => {
    expect(roundQuantity(18.4, { step: 1, mode: 'NEAREST' })).toBe(18);
    expect(roundQuantity(18.5, { step: 1, mode: 'NEAREST' })).toBe(19);
  });

  it('respecte un pas de lot', () => {
    expect(roundQuantity(13, { step: 6, mode: 'CEIL' })).toBe(18);
    expect(roundQuantity(12, { step: 6, mode: 'CEIL' })).toBe(12);
    expect(roundQuantity(13, { step: 6, mode: 'NEAREST' })).toBe(12);
  });

  it('respecte un pas décimal', () => {
    expect(roundQuantity(0.26, { step: 0.5, mode: 'CEIL' })).toBe(0.5);
    expect(roundQuantity(1.2, { step: 0.1, mode: 'CEIL' })).toBe(1.2);
  });

  it('laisse la valeur intacte en mode NONE', () => {
    expect(roundQuantity(164.45, { step: 1, mode: 'NONE' })).toBe(164.45);
  });

  it('évite les artefacts de virgule flottante', () => {
    // 18.9 / 0.1 vaut 188.99999… en flottant : sans nettoyage, CEIL renverrait 18.9 + 0.1.
    expect(roundQuantity(18.9, { step: 0.1, mode: 'CEIL' })).toBe(18.9);
  });

  it('ignore un pas invalide au lieu de renvoyer NaN/Infinity', () => {
    expect(roundQuantity(18.3, { step: 0, mode: 'CEIL' })).toBe(18.3);
    expect(roundQuantity(18.3, { step: -2, mode: 'CEIL' })).toBe(18.3);
  });

  it('renvoie 0 pour une valeur non finie', () => {
    expect(roundQuantity(Number.NaN, { step: 1, mode: 'CEIL' })).toBe(0);
  });

  it('gère 0', () => {
    expect(roundQuantity(0, { step: 6, mode: 'CEIL' })).toBe(0);
  });
});
