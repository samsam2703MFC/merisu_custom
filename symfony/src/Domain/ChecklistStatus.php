<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qu'il est advenu d'un point de check-list.
 *
 * Une case à cocher ne disait qu'une chose : fait, ou pas. Or « pas coché »
 * recouvrait trois situations qui n'appellent pas la même réaction — le point
 * n'a pas encore été traité, il ne s'appliquait pas ce jour-là, ou il a
 * échoué. Le lendemain matin, seule la troisième demande une action, et rien
 * ne permettait de la distinguer des deux autres.
 *
 * ATTENTE et PASSÉ ne sont pas des défauts. ÉCHEC en est un, et il est le seul
 * à exiger un motif : un échec sans raison ne sert à personne le lendemain.
 */
enum ChecklistStatus: string
{
    case Pending = 'PENDING';
    case Done = 'DONE';
    case Skipped = 'SKIPPED';
    case Failed = 'FAILED';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Pending, self::Done, self::Skipped, self::Failed];
    }

    public static function fromLoose(?string $value): self
    {
        return self::tryFromLoose($value) ?? self::Pending;
    }

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtoupper(trim($value)));
    }

    /** Statuts qu'un vendeur peut poser lui-même. ATTENTE n'en est pas un. */
    public static function selectable(): array
    {
        return [self::Done, self::Skipped, self::Failed];
    }

    /**
     * Le point est-il traité ?
     *
     * « Passé » compte comme traité : la personne a examiné le point et a
     * décidé qu'il ne s'appliquait pas. Ce n'est pas un oubli.
     */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }

    /** Un motif est-il exigé ? */
    public function needsReason(): bool
    {
        return $this === self::Failed;
    }

    /** Le point réclame-t-il une action le lendemain ? */
    public function isProblem(): bool
    {
        return $this === self::Failed;
    }

    /** Variante de pastille, dans le vocabulaire du système de design. */
    public function badge(): string
    {
        return match ($this) {
            self::Done => 'ok',
            self::Skipped => 'warn',
            self::Failed => 'danger',
            self::Pending => 'neutral',
        };
    }
}
