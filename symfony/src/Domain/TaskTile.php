<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Les tâches du poste, telles qu'elles s'ouvrent depuis le menu.
 *
 * Ce sont les TUILES de l'écran d'accueil, et ce sont aussi les droits : un
 * vendeur qui n'a pas le comptage du soir ne le voit pas, et ne peut pas non
 * plus l'atteindre en tapant son adresse. Une tuile masquée qui reste
 * accessible par son URL n'est pas une permission, c'est une décoration.
 *
 * ── Le poste de travail est une AUTRE notion
 *
 * Un poste (« stanowisko », ws-1, ws-2) est l'endroit où l'on compte ; une
 * tuile est ce qu'on y fait. Une personne peut être affectée à deux postes et
 * n'avoir droit qu'à la check-list sur les deux.
 */
enum TaskTile: string
{
    case Morning = 'MORNING';
    case Evening = 'EVENING';
    case Checklist = 'CHECKLIST';
    case Produce = 'PRODUCE';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Morning, self::Evening, self::Checklist, self::Produce];
    }

    /** Lit une valeur venue d'un formulaire ou de la base, sans jamais échouer. */
    public static function tryFromLoose(mixed $value): ?self
    {
        return is_scalar($value) ? self::tryFrom(strtoupper(trim((string) $value))) : null;
    }

    /**
     * Nettoie une liste de tuiles.
     *
     * Rend une liste dédoublonnée, dans l'ordre du menu — celui-ci ne dépend
     * donc pas de l'ordre dans lequel les cases ont été cochées.
     *
     * @param iterable<mixed> $values
     *
     * @return list<self>
     */
    public static function cleanList(iterable $values): array
    {
        $vues = [];

        foreach ($values as $value) {
            $tuile = self::tryFromLoose($value);
            if ($tuile !== null) {
                $vues[$tuile->value] = true;
            }
        }

        return array_values(array_filter(self::all(), static fn (self $t): bool => isset($vues[$t->value])));
    }

    /** La clé de traduction du nom affiché, celle du menu. */
    public function labelKey(): string
    {
        return match ($this) {
            self::Morning => 'nav.morning',
            self::Evening => 'nav.evening',
            self::Checklist => 'nav.checklist',
            self::Produce => 'produce.tileTitle',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Morning => 'sunrise',
            self::Evening => 'moon',
            self::Checklist => 'checklist',
            self::Produce => 'tray',
        };
    }
}
