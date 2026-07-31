<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * Implémentation de repli, active tant que le module « Recette technique » n'est
 * pas branché.
 *
 * ⚠️ Recettes et matières FICTIVES : elles servent uniquement à démontrer le
 * calcul du delta technique. Les vraies viendront du module existant.
 */
final class LocalRecipeService implements RecipeServiceInterface
{
    /** @var array<string, array{name: array<string,string>, unit: string}> */
    private const MATERIALS = [
        'mat-milk' => ['name' => ['fr' => 'Lait', 'pl' => 'Mleko', 'it' => 'Latte', 'es' => 'Leche'], 'unit' => 'l'],
        'mat-sugar' => ['name' => ['fr' => 'Sucre', 'pl' => 'Cukier', 'it' => 'Zucchero', 'es' => 'Azúcar'], 'unit' => 'kg'],
        'mat-coffee' => ['name' => ['fr' => 'Café', 'pl' => 'Kawa', 'it' => 'Caffè', 'es' => 'Café'], 'unit' => 'kg'],
        'mat-flour' => ['name' => ['fr' => 'Farine', 'pl' => 'Mąka', 'it' => 'Farina', 'es' => 'Harina'], 'unit' => 'kg'],
    ];

    /**
     * Nomenclatures indexées par identifiant de produit.
     *
     * @var array<string, array<string, float>>
     */
    private const RECIPES = [
        'product-1' => ['mat-milk' => 0.2, 'mat-sugar' => 0.05],
        'product-2' => ['mat-milk' => 0.25, 'mat-sugar' => 0.04],
        'product-3' => ['mat-milk' => 0.18, 'mat-sugar' => 0.06],
        'product-4' => ['mat-coffee' => 0.008, 'mat-milk' => 0.1],
        'product-5' => ['mat-flour' => 0.03, 'mat-sugar' => 0.01],
        'product-6' => ['mat-coffee' => 0.01],
    ];

    public function recipes(array $productIds): array
    {
        $wanted = array_flip($productIds);

        return array_intersect_key(self::RECIPES, $wanted);
    }

    public function materials(): array
    {
        $out = [];
        foreach (self::MATERIALS as $id => $material) {
            $out[] = new Material($id, $material['name'], $material['unit']);
        }

        return $out;
    }

    public function material(string $id): ?Material
    {
        $material = self::MATERIALS[$id] ?? null;

        return $material === null ? null : new Material($id, $material['name'], $material['unit']);
    }
}
