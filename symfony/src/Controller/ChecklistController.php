<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ChecklistSection;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Check-list du poste : ouverture, fermeture, contrôle qualité.
 *
 * Elle accompagne les deux comptages sans s'y mêler. Un comptage se valide et
 * se verrouille — c'est une donnée chiffrée qui alimente le plan de production.
 * Une check-list se coche et se décoche tant que la journée dure : un point
 * oublié doit pouvoir être rattrapé, et un point coché par erreur repris.
 * Chaque changement est daté et signé, ce qui suffit à la traçabilité.
 */
final class ChecklistController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly InventoryService $inventory,
        private readonly Store $store,
    ) {
    }

    #[Route('/check-list', name: 'checklist', methods: ['GET'])]
    public function show(): Response
    {
        $this->currentUser->requireConsultant();

        $date = $this->inventory->today();
        $workstationId = $this->currentUser->resolveWorkstation();

        return $this->render('count/checklist.html.twig', [
            'date' => $date,
            'workstationId' => $workstationId,
            'sections' => $this->sections($date, $workstationId),
        ]);
    }

    #[Route('/check-list/enregistrer', name: 'checklist_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $consultant = $this->currentUser->requireConsultant();

        $date = $this->inventory->today();
        $workstationId = $this->currentUser->resolveWorkstation();

        /** @var array<string,mixed> $coches */
        $coches = $request->request->all('checked');
        /** @var array<string,mixed> $notes */
        $notes = $request->request->all('note');

        $faits = 0;

        foreach ($this->store->checklistItems(true) as $item) {
            // Une case décochée n'est pas envoyée par le navigateur : c'est la
            // liste des points connus qui fait foi, pas celle des cases reçues.
            $coche = isset($coches[$item->id]);
            $note = trim((string) ($notes[$item->id] ?? ''));

            $this->store->setChecklistEntry(
                $date,
                $workstationId,
                $item->id,
                $coche,
                $consultant->id,
                $note === '' ? null : $note,
            );

            if ($coche) {
                ++$faits;
            }
        }

        // §4 — l'enregistrement laisse une trace : qui, quand, où, combien.
        $this->store->audit(
            $consultant->id,
            $consultant->role->value,
            'CHECKLIST_SAVE',
            $workstationId,
            $date,
            ['checked' => $faits],
        );

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('checklist');
    }

    /**
     * Les trois volets, chacun avec ses points et son avancement.
     *
     * @return list<array<string,mixed>>
     */
    private function sections(string $date, string $workstationId): array
    {
        $items = $this->store->checklistItems(true);
        $entries = $this->store->checklistEntries($date, $workstationId);

        $sections = [];

        foreach (ChecklistSection::all() as $section) {
            $ofSection = array_values(array_filter(
                $items,
                static fn (ChecklistItem $i): bool => $i->section === $section,
            ));

            $lignes = [];
            $obligatoires = 0;
            $obligatoiresFaits = 0;

            foreach ($ofSection as $item) {
                $entry = $entries[$item->id] ?? null;
                $coche = $entry?->checked ?? false;

                if ($item->required) {
                    ++$obligatoires;
                    if ($coche) {
                        ++$obligatoiresFaits;
                    }
                }

                $lignes[] = [
                    'item' => $item,
                    'checked' => $coche,
                    'note' => $entry?->note,
                    'by' => $entry?->consultantId,
                    'at' => $coche ? $entry?->checkedAt : null,
                ];
            }

            $sections[] = [
                'section' => $section,
                'rows' => $lignes,
                'total' => \count($lignes),
                'done' => \count(array_filter($lignes, static fn (array $l): bool => $l['checked'])),
                // « Complet » se juge sur les seuls points obligatoires : un
                // volet sans point obligatoire n'est jamais en défaut.
                'complete' => $obligatoires > 0 && $obligatoiresFaits === $obligatoires,
            ];
        }

        return $sections;
    }

    /**
     * Avancement global, pour la tuile du menu des tâches.
     *
     * @return array{done: int, total: int, complete: bool}
     */
    public function progress(string $date, string $workstationId): array
    {
        $items = $this->store->checklistItems(true);
        $entries = $this->store->checklistEntries($date, $workstationId);

        $total = \count($items);
        $done = 0;
        $manquants = 0;

        foreach ($items as $item) {
            $coche = ($entries[$item->id] ?? null)?->checked ?? false;

            if ($coche) {
                ++$done;
            } elseif ($item->required) {
                ++$manquants;
            }
        }

        return ['done' => $done, 'total' => $total, 'complete' => $total > 0 && $manquants === 0];
    }
}
