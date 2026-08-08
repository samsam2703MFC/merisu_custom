<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Le mois d'un jeu d'objectifs — et le corps que l'hôte attend.
 *
 * L'année commence en 2020 et le mois va de 1 à 12 : ce sont les bornes du
 * contrat TF Buddy (`minimum: 2020`), reprises telles quelles. Les respecter
 * ici évite un aller-retour réseau pour se faire refuser une saisie qu'on
 * pouvait juger sur place.
 */
final readonly class TargetMonth
{
    public const FIRST_YEAR = 2020;

    private function __construct(
        public int $year,
        public int $month,
    ) {
    }

    public static function of(mixed $year, mixed $month): ?self
    {
        $a = is_numeric($year) ? (int) $year : 0;
        $m = is_numeric($month) ? (int) $month : 0;

        if ($a < self::FIRST_YEAR || $m < 1 || $m > 12) {
            return null;
        }

        return new self($a, $m);
    }

    /** Le mois d'une date métier `YYYY-MM-DD`, ou null si elle n'en est pas une. */
    public static function fromDate(string $businessDate): ?self
    {
        if (!BusinessDate::isValid($businessDate)) {
            return null;
        }

        return self::of((int) substr($businessDate, 0, 4), (int) substr($businessDate, 5, 2));
    }

    public function previous(): self
    {
        return $this->month === 1
            ? new self($this->year - 1, 12)
            : new self($this->year, $this->month - 1);
    }

    public function next(): self
    {
        return $this->month === 12
            ? new self($this->year + 1, 1)
            : new self($this->year, $this->month + 1);
    }

    /** « 2026-08 » — la forme courte des listes déroulantes et des URL. */
    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /**
     * Le corps de `PUT /shops/{id}/targets`, au format de l'hôte.
     *
     * Écrit ICI plutôt que dans l'adaptateur : le jour où l'intégration se
     * branche, l'adaptateur n'aura plus qu'à poster ce tableau. Et en
     * attendant, il est testable sans réseau.
     *
     * @param list<MetricTarget> $targets
     *
     * @return array{year: int, month: int, author_id: int, targets: list<array<string, float|string>>}
     */
    public function toHost(array $targets, int $authorId): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            // `author_id` est EXIGÉ par le contrat, et minimum 1 : c'est qui a
            // posé l'objectif, information que l'hôte conserve. Nos
            // identifiants sont textuels ; la correspondance se fera au
            // branchement, d'où le paramètre plutôt qu'une lecture directe.
            'author_id' => max(1, $authorId),
            'targets' => array_map(static fn (MetricTarget $t): array => $t->toHost(), $targets),
        ];
    }
}
