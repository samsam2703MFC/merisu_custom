<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Les 4 langues obligatoires du projet (§2). */
enum Locale: string
{
    case Fr = 'fr';
    case Pl = 'pl';
    case It = 'it';
    case Es = 'es';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Fr, self::Pl, self::It, self::Es];
    }

    /** Accepte « fr-FR », « pl_PL »… pour la détection navigateur. */
    public static function tryFromLoose(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower(explode('-', str_replace('_', '-', $value))[0]));
    }
}
