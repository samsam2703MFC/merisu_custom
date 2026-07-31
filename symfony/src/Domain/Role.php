<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Rôles applicatifs (§2 — rôles & permissions). */
enum Role: string
{
    case Consultant = 'CONSULTANT';
    case Admin = 'ADMIN';

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
