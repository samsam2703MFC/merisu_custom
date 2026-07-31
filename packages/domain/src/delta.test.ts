import { describe, expect, it } from 'vitest';

import { computeMaterialDeltas, computeTheoreticalConsumption, summarizeDeltas } from './delta.js';
import { makeRecipe } from './testFixtures.js';

describe('§6 — consommation théorique depuis les recettes', () => {
  const recipes = [
    makeRecipe('p1', [
      ['lait', 0.2],
      ['sucre', 0.05],
    ]),
    makeRecipe('p2', [
      ['lait', 0.1],
      ['cafe', 0.008],
    ]),
  ];

  it('multiplie les pièces produites par la quantité unitaire', () => {
    const { byMaterial } = computeTheoreticalConsumption({
      produced: [{ productId: 'p1', producedQty: 100 }],
      recipes,
    });

    expect(byMaterial['lait']).toBeCloseTo(20, 9);
    expect(byMaterial['sucre']).toBeCloseTo(5, 9);
  });

  it('cumule une même matière utilisée par plusieurs produits', () => {
    const { byMaterial } = computeTheoreticalConsumption({
      produced: [
        { productId: 'p1', producedQty: 100 },
        { productId: 'p2', producedQty: 50 },
      ],
      recipes,
    });

    expect(byMaterial['lait']).toBeCloseTo(25, 9);
    expect(byMaterial['cafe']).toBeCloseTo(0.4, 9);
  });

  it('signale les produits sans recette au lieu de fausser le total', () => {
    const { byMaterial, productsWithoutRecipe } = computeTheoreticalConsumption({
      produced: [
        { productId: 'p1', producedQty: 10 },
        { productId: 'inconnu', producedQty: 40 },
      ],
      recipes,
    });

    expect(productsWithoutRecipe).toEqual(['inconnu']);
    expect(byMaterial['lait']).toBeCloseTo(2, 9);
  });

  it('ignore un produit sans recette mais sans production', () => {
    const { productsWithoutRecipe } = computeTheoreticalConsumption({
      produced: [{ productId: 'inconnu', producedQty: 0 }],
      recipes,
    });

    expect(productsWithoutRecipe).toEqual([]);
  });
});

describe('§6 — delta technique par matière', () => {
  it('calcule delta et pourcentage', () => {
    const [delta] = computeMaterialDeltas({
      theoreticalByMaterial: { lait: 20 },
      realByMaterial: { lait: 22 },
      tolerance: 0.05,
    });

    expect(delta!.delta).toBe(2);
    expect(delta!.deltaPct).toBeCloseTo(0.1, 9);
    expect(delta!.outOfTolerance).toBe(true);
  });

  it('reste dans la tolérance pour un petit écart', () => {
    const [delta] = computeMaterialDeltas({
      theoreticalByMaterial: { lait: 20 },
      realByMaterial: { lait: 20.5 },
      tolerance: 0.05,
    });

    expect(delta!.deltaPct).toBeCloseTo(0.025, 9);
    expect(delta!.outOfTolerance).toBe(false);
  });

  it('traite un delta négatif (consommation inférieure) avec la même tolérance', () => {
    const [delta] = computeMaterialDeltas({
      theoreticalByMaterial: { lait: 20 },
      realByMaterial: { lait: 16 },
      tolerance: 0.05,
    });

    expect(delta!.delta).toBe(-4);
    expect(delta!.deltaPct).toBeCloseTo(-0.2, 9);
    expect(delta!.outOfTolerance).toBe(true);
  });

  it('ne déclenche pas la tolérance exactement à la limite', () => {
    const [delta] = computeMaterialDeltas({
      theoreticalByMaterial: { lait: 100 },
      realByMaterial: { lait: 105 },
      tolerance: 0.05,
    });

    expect(delta!.deltaPct).toBeCloseTo(0.05, 9);
    expect(delta!.outOfTolerance).toBe(false);
  });

  it('gère un théorique à 0 avec consommation réelle (division par zéro)', () => {
    const [delta] = computeMaterialDeltas({
      theoreticalByMaterial: { lait: 0 },
      realByMaterial: { lait: 3 },
      tolerance: 0.05,
    });

    expect(delta!.delta).toBe(3);
    expect(delta!.deltaPct).toBeNull();
    expect(delta!.outOfTolerance).toBe(true);
  });

  it('reste neutre quand théorique et réel valent 0', () => {
    const [delta] = computeMaterialDeltas({
      theoreticalByMaterial: { lait: 0 },
      realByMaterial: { lait: 0 },
      tolerance: 0.05,
    });

    expect(delta!.outOfTolerance).toBe(false);
  });

  it('marque « partiel » quand la consommation réelle est inconnue', () => {
    const [delta] = computeMaterialDeltas({
      theoreticalByMaterial: { lait: 20 },
      realByMaterial: {},
      tolerance: 0.05,
    });

    expect(delta!.partial).toBe(true);
    expect(delta!.delta).toBeNull();
    expect(delta!.outOfTolerance).toBe(false);
  });

  it('inclut les matières présentes uniquement côté réel', () => {
    const deltas = computeMaterialDeltas({
      theoreticalByMaterial: { lait: 1 },
      realByMaterial: { lait: 1, sucre: 2 },
      tolerance: 0.05,
    });

    expect(deltas.map((d) => d.materialId)).toEqual(['lait', 'sucre']);
    expect(deltas[1]!.theoreticalQty).toBe(0);
  });

  it('résume le rapport pour l’entête admin', () => {
    const deltas = computeMaterialDeltas({
      theoreticalByMaterial: { lait: 20, sucre: 10 },
      realByMaterial: { lait: 30 },
      tolerance: 0.05,
    });

    expect(summarizeDeltas(deltas)).toEqual({ materials: 2, outOfTolerance: 1, partial: true });
  });
});
