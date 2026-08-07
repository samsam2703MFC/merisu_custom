<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Où en est une remontée vers le système hôte. */
enum SyncStatus: string
{
    /** En file, pas encore envoyée. */
    case Pending = 'PENDING';

    /** Acceptée par l'hôte. Terminée. */
    case Sent = 'SENT';

    /**
     * Abandonnée après trop de tentatives.
     *
     * Elle n'est pas perdue : la ligne reste en base avec sa charge utile et
     * sa dernière erreur, et un administrateur peut la remettre en file. Une
     * remontée effacée sur échec ferait disparaître un comptage réel.
     */
    case Failed = 'FAILED';

    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
