<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Qui a le droit d'ouvrir quelle tuile.
 *
 * ── Une liste VIDE vaut « tout »
 *
 * C'est la décision qui compte ici, et elle n'est pas anodine. Les fiches déjà
 * en base n'ont aucune tuile enregistrée : lire cela comme « aucun droit »
 * aurait, à la première mise à jour, renvoyé toute la boutique sur un menu
 * vide un matin à huit heures — sans que personne comprenne pourquoi, et sans
 * qu'un vendeur puisse y remédier.
 *
 * Restreindre est donc un geste DÉLIBÉRÉ : tant qu'on n'a rien coché, tout le
 * monde fait tout, comme avant. La contrepartie est assumée — on ne peut pas
 * retirer TOUTES les tuiles à quelqu'un ; retirer la dernière revient à tout
 * lui rendre. Pour écarter quelqu'un, on le désactive, ce que l'écran Équipe
 * sait déjà faire et qui dit bien ce qu'il fait.
 *
 * ── L'administrateur n'est pas concerné
 *
 * Il règle les droits ; il ne se les applique pas à lui-même. Un
 * administrateur qui se serait retiré le comptage du soir n'aurait plus aucun
 * moyen de le rendre à quiconque.
 */
final class TaskAccess
{
    /**
     * @param list<TaskTile> $allowed tuiles enregistrées sur la fiche
     */
    public static function allows(array $allowed, TaskTile $tile, Role $role): bool
    {
        if ($role->isAdmin() || $allowed === []) {
            return true;
        }

        return in_array($tile, $allowed, true);
    }

    /**
     * Les tuiles réellement ouvertes à quelqu'un, dans l'ordre du menu.
     *
     * @param list<TaskTile> $allowed
     *
     * @return list<TaskTile>
     */
    public static function open(array $allowed, Role $role): array
    {
        return array_values(array_filter(
            TaskTile::all(),
            static fn (TaskTile $t): bool => self::allows($allowed, $t, $role),
        ));
    }

    /**
     * La fiche porte-t-elle une restriction ?
     *
     * Sert à l'écran d'administration, qui doit distinguer « tout, parce que
     * rien n'est réglé » de « tout, parce que les quatre cases sont cochées » —
     * ce sont deux états différents, et le second ne survivrait pas à l'ajout
     * d'une cinquième tuile.
     *
     * @param list<TaskTile> $allowed
     */
    public static function isRestricted(array $allowed): bool
    {
        return $allowed !== [] && count($allowed) < count(TaskTile::all());
    }
}
