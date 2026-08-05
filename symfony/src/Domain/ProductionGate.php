<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qui autorise — ou non — à produire.
 *
 * Deux verrous, de natures différentes :
 *
 * · l'ARRÊT est une décision. Quelqu'un a dit « on ne produit plus », pour un
 *   motif daté. Rien d'autre ne le lève que la reprise explicite.
 * · la CHECK-LIST est une condition. On ne produit pas ENCORE : il reste des
 *   points obligatoires à cocher, et cocher suffit à ouvrir.
 *
 * La règle est ici plutôt que dans le contrôleur parce qu'elle porte une
 * subtilité qu'aucun écran ne rend évidente : le volet « Fermeture » ne bloque
 * jamais. Ses points se cochent le soir, APRÈS la production. Les exiger avant
 * rendrait la production définitivement inaccessible — un verrou dont personne
 * n'aurait la clé.
 */
final readonly class ProductionGate
{
    /**
     * Points obligatoires encore à cocher, dans l'ordre reçu.
     *
     * @param list<ChecklistItem>  $items   Points actifs de la check-list
     * @param array<string, bool>  $checked Coché ou non, indexé par identifiant
     *
     * @return list<ChecklistItem> Vide = la check-list ne s'oppose à rien
     */
    public static function blockingItems(array $items, array $checked): array
    {
        $blocking = [];

        foreach ($items as $item) {
            if (!$item->required || $item->section === ChecklistSection::Closing) {
                continue;
            }

            // Un identifiant absent vaut « pas coché » : une case jamais
            // touchée et une case décochée bloquent aussi bien l'une que l'autre.
            if (($checked[$item->id] ?? false) === false) {
                $blocking[] = $item;
            }
        }

        return $blocking;
    }

    /**
     * @param list<ChecklistItem> $blocking Résultat de `blockingItems()`
     */
    public static function allows(?ProductionStop $stop, array $blocking): bool
    {
        // Un arrêt levé ne bloque plus rien : c'est `isActive()` qui tranche,
        // pas la simple existence d'un arrêt dans l'historique.
        if ($stop !== null && $stop->isActive()) {
            return false;
        }

        return $blocking === [];
    }
}
