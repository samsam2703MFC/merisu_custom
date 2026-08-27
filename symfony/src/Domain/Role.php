<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Rôles applicatifs (§2 — rôles & permissions).
 *
 * ── Trois rôles, et le manager est le nouveau
 *
 * Le CONSULTANT compte au poste. L'ADMIN règle le réseau entier. Entre les
 * deux manquait celui qui pilote SES boutiques : lit leurs chiffres, fixe
 * leurs objectifs, tient leurs seuils — sans voir celles des autres ni toucher
 * au catalogue commun.
 *
 * ── `isAdmin()` reste STRICT, et c'est délibéré
 *
 * Treize contrôles s'appuient dessus, et quinze contrôleurs sur
 * `requireAdmin()`. Y faire entrer le manager aurait ouvert le réseau entier
 * d'un coup, en silence : les paramètres généraux, la caisse, le catalogue,
 * les chiffres des boutiques voisines. Un élargissement de droits qui se fait
 * par un `||` dans une méthode existante ne se voit dans aucune revue.
 *
 * `canManage()` répond donc à une question DIFFÉRENTE — « cette personne
 * pilote-t-elle quelque chose ? » — et chaque écran choisit celle qu'il pose.
 */
enum Role: string
{
    case Consultant = 'CONSULTANT';
    case Manager = 'MANAGER';
    case Admin = 'ADMIN';

    /** Le réseau ENTIER. Ni le manager ni le consultant. */
    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /** Pilote quelque chose : ses boutiques pour le manager, tout pour l'admin. */
    public function canManage(): bool
    {
        return $this === self::Admin || $this === self::Manager;
    }

    public function isManager(): bool
    {
        return $this === self::Manager;
    }

    /** @return list<self> Dans l'ordre croissant de pouvoir. */
    public static function all(): array
    {
        return [self::Consultant, self::Manager, self::Admin];
    }
}
