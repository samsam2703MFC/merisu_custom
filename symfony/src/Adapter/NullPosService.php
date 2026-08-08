<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * Implémentation d'attente : elle ne lit rien, et le dit.
 *
 * Utile pour couper la fonction sans toucher au code — il suffit de la brancher
 * à la place de `GoPosService` dans `config/services.yaml`. `GoPosService` sans
 * identifiants se comporte déjà ainsi ; celle-ci existe pour le cas où l'on
 * veut la certitude qu'AUCUN appel ne sort, même si des identifiants traînent
 * dans l'environnement.
 */
final class NullPosService implements PosServiceInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function ping(): string
    {
        throw new PosUnavailable('admin.pos.notConfigured');
    }

    public function categories(): array
    {
        throw new PosUnavailable('admin.pos.notConfigured');
    }

    public function items(): array
    {
        throw new PosUnavailable('admin.pos.notConfigured');
    }
}
