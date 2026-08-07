<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qu'un comptage validé emporte vers le système hôte.
 *
 * Une charge utile NEUTRE, et volontairement : le corps attendu par TF Buddy
 * n'est pas décrit dans sa spécification (routes relevées automatiquement, qui
 * annoncent elles-mêmes ne pas décrire leurs contrats). Écrire ici un corps
 * deviné aurait figé une hypothèse invérifiable au cœur du domaine.
 *
 * Ce que cette classe garantit, en revanche, tient tout entier et se teste :
 *
 * · TOUT ce qu'un hôte peut demander est là. Date métier, poste, moment,
 *   référence côté hôte, quantité, unité, et QUI a validé. Ajouter un champ
 *   après coup obligerait à rejouer des comptages déjà partis ;
 * · rien qui n'ait de sens hors d'ici. L'identifiant interne du produit
 *   accompagne la référence hôte à titre de trace, jamais comme clé ;
 * · une ligne sans référence hôte n'est PAS mise en file. Elle partirait vers
 *   un produit inconnu, serait refusée, et huit tentatives plus tard un
 *   comptage réel finirait « en échec » pour une case laissée vide en
 *   administration. Elle est signalée à l'administrateur, ce qui est le seul
 *   endroit où le problème se corrige.
 *
 * La mise en forme définitive — noms de champs, enveloppe — appartient à
 * l'implémentation de `InventorySyncInterface`, le jour où le contrat arrive.
 */
final readonly class SyncPayload
{
    /**
     * Lignes remontables d'un comptage.
     *
     * @param list<Product>            $products
     * @param array<string, float>     $quantities quantités par identifiant de produit
     *
     * @return array{ready: list<array<string,mixed>>, missingRef: list<string>}
     */
    public static function forCounts(
        array $products,
        array $quantities,
        string $date,
        string $workstationId,
        CountMoment $moment,
        string $actorId,
        ?string $shopId,
    ): array {
        $pretes = [];
        $sansReference = [];

        foreach ($products as $product) {
            if (!\array_key_exists($product->id, $quantities)) {
                continue;
            }

            // Le pont sur les identifiants : `recipeRef` porte l'identifiant du
            // produit côté hôte, saisi dans Admin ▸ Produits.
            $reference = trim((string) ($product->recipeRef ?? ''));

            if ($reference === '') {
                $sansReference[] = $product->id;
                continue;
            }

            $pretes[] = [
                'shopId' => $shopId,
                'workstationId' => $workstationId,
                'externalProductId' => $reference,
                // Conservé pour la trace, jamais comme clé chez l'hôte : les
                // deux systèmes numérotent leurs produits chacun de son côté.
                'productId' => $product->id,
                'businessDate' => $date,
                'moment' => $moment->value,
                'qty' => Rounding::clean($quantities[$product->id]),
                'unit' => $product->unit,
                'nature' => $product->nature->value,
                'validatedBy' => $actorId,
            ];
        }

        return ['ready' => $pretes, 'missingRef' => $sansReference];
    }

    /**
     * Regroupe les lignes selon l'endroit où l'hôte les attend.
     *
     * Les produits finis se posent un par un
     * (`PATCH /shops/{id}/products/{id}/inventory`), les matières premières
     * partent d'un bloc (`POST /shops/{id}/materials/stocktakings`). Le tri se
     * fait sur la NATURE, déjà portée par chaque fiche.
     *
     * @param list<array<string,mixed>> $lines
     *
     * @return list<array{kind: SyncKind, payload: array<string,mixed>}>
     */
    public static function group(array $lines): array
    {
        $matieres = [];
        $sortie = [];

        foreach ($lines as $ligne) {
            if (($ligne['nature'] ?? '') === ProductNature::Raw->value) {
                $matieres[] = $ligne;
                continue;
            }

            $sortie[] = ['kind' => SyncKind::ProductInventory, 'payload' => $ligne];
        }

        if ($matieres !== []) {
            $sortie[] = ['kind' => SyncKind::MaterialStocktaking, 'payload' => [
                'shopId' => $matieres[0]['shopId'],
                'workstationId' => $matieres[0]['workstationId'],
                'businessDate' => $matieres[0]['businessDate'],
                'moment' => $matieres[0]['moment'],
                'validatedBy' => $matieres[0]['validatedBy'],
                'items' => array_map(
                    static fn (array $l): array => [
                        'externalProductId' => $l['externalProductId'],
                        'qty' => $l['qty'],
                        'unit' => $l['unit'],
                    ],
                    $matieres,
                ),
            ]];
        }

        return $sortie;
    }
}
