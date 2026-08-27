<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Le SUIVI d'une check-list : ce qui a été fait, par qui, et ce qui traîne.
 *
 * ── Une ligne par POINT, toutes les signatures dessous
 *
 * Les signatures sont par poste : une boutique à deux postes peut avoir signé
 * le même point deux fois, et les taire ferait disparaître la moitié du
 * travail. Chaque ligne porte donc TOUTES ses signatures, et un statut résolu
 * qui les résume pour le compteur.
 *
 * ── Le statut résolu fait remonter les PROBLÈMES
 *
 *   un échec quelque part  → FAILED, même si un autre poste a réussi ;
 *   sinon un fait          → DONE ;
 *   sinon un passé         → SKIPPED ;
 *   sinon                  → PENDING.
 *
 * L'échec gagne sur le fait, et c'est voulu : un point raté à un poste mérite
 * des yeux même quand le poste voisin a réussi. L'ordre inverse aurait fait
 * du suivi un tableau vert où les ennuis se cachent sous les succès — le
 * mensonge le plus tranquille d'un écran de contrôle.
 */
final readonly class ChecklistReview
{
    private function __construct(
        /** @var list<array{item: ChecklistItem, entries: list<ChecklistEntry>, status: ChecklistStatus}> */
        public array $rows,
        public int $done,
        public int $problems,
        public int $total,
    ) {
    }

    /**
     * @param list<ChecklistItem>  $items   les points de LA liste, dans l'ordre
     * @param list<ChecklistEntry> $entries les signatures du jour, tous postes
     */
    public static function build(array $items, array $entries): self
    {
        $parPoint = [];
        foreach ($entries as $entry) {
            $parPoint[$entry->itemId][] = $entry;
        }

        $rows = [];
        $done = 0;
        $problems = 0;

        foreach ($items as $item) {
            $siennes = $parPoint[$item->id] ?? [];

            $status = ChecklistStatus::Pending;
            foreach ($siennes as $entry) {
                if ($entry->status === ChecklistStatus::Failed) {
                    $status = ChecklistStatus::Failed;
                    break;
                }
                if ($entry->status === ChecklistStatus::Done) {
                    $status = ChecklistStatus::Done;
                } elseif ($entry->status === ChecklistStatus::Skipped && $status === ChecklistStatus::Pending) {
                    $status = ChecklistStatus::Skipped;
                }
            }

            if ($status === ChecklistStatus::Done) {
                ++$done;
            }
            if ($status === ChecklistStatus::Failed) {
                ++$problems;
            }

            $rows[] = ['item' => $item, 'entries' => $siennes, 'status' => $status];
        }

        return new self($rows, $done, $problems, \count($items));
    }

    /** Tout ce qui devait l'être est-il fait ? Les points facultatifs ne bloquent pas. */
    public function complete(): bool
    {
        foreach ($this->rows as $row) {
            if ($row['item']->required && $row['status'] !== ChecklistStatus::Done) {
                return false;
            }
        }

        return $this->total > 0;
    }
}
