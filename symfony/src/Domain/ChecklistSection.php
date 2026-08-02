<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Les trois volets de la check-list du poste.
 *
 * Ouverture et fermeture encadrent la journée, comme les deux comptages ;
 * le contrôle qualité s'en distingue : il ne dépend pas d'une heure, il se
 * fait quand la production le demande.
 */
enum ChecklistSection: string
{
    case Opening = 'OPENING';
    case Closing = 'CLOSING';
    case Quality = 'QUALITY';

    /** @return list<self> Dans l'ordre d'affichage, qui est celui de la journée. */
    public static function all(): array
    {
        return [self::Opening, self::Closing, self::Quality];
    }

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtoupper(trim($value)));
    }

    /** Icône associée, reprise du menu des tâches pour rester cohérent. */
    public function icon(): string
    {
        return match ($this) {
            self::Opening => 'sunrise',
            self::Closing => 'moon',
            self::Quality => 'shield',
        };
    }

    /** Clé de traduction du titre de section. */
    public function labelKey(): string
    {
        return 'checklist.sections.' . $this->value;
    }
}
