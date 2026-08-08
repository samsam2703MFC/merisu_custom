<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\SyncStatus;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\SyncService;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Remontées — la file d'envoi vers le système hôte.
 *
 * Cet écran existe parce que le bandeau d'accueil sans lui était une impasse :
 * il annonçait des remontées abandonnées et renvoyait vers une commande en
 * ligne, que l'administrateur d'une boutique n'a aucun moyen de lancer. Un
 * problème signalé sans geste pour le résoudre vaut à peine mieux qu'un
 * problème caché.
 *
 * Trois gestes, et rien d'autre : voir, reprendre une ligne, vider la file.
 * Aucune suppression — une remontée effacée ferait disparaître la trace d'un
 * comptage réel (§5).
 */
#[Route('/admin/remontees')]
final class AdminSyncController extends AbstractController
{
    public function __construct(
        private readonly Store $store,
        private readonly CurrentUser $currentUser,
        private readonly SyncService $sync,
    ) {
    }

    #[Route('', name: 'admin_sync', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        $filtre = SyncStatus::tryFrom(strtoupper((string) $request->query->get('etat', '')));

        return $this->render('admin/sync.html.twig', [
            'entries' => $this->store->syncEntries($filtre),
            'counts' => $this->store->syncCounts(),
            'filter' => $filtre,
            'statuses' => SyncStatus::cases(),
            // Ce que l'écran doit dire avant tout : tant qu'aucune intégration
            // n'est branchée, « en attente » est l'état NORMAL et non un retard.
            'configured' => $this->sync->isConfigured(),
        ]);
    }

    /**
     * Vide la file, à la demande.
     *
     * Le même travail que `merisu:synchroniser`, depuis le navigateur : une
     * tâche planifiée finira par passer, mais l'administrateur qui vient de
     * corriger ce qui bloquait veut le vérifier tout de suite.
     */
    #[Route('/envoyer', name: 'admin_sync_drain', methods: ['POST'])]
    public function drain(): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $bilan = $this->sync->drain();

        $this->store->audit($admin->id, $admin->role->value, 'SYNC_DRAINED', null, null, $bilan);

        if ($bilan['skipped'] > 0) {
            $this->addFlash('error', 'admin.sync.notConfigured');
        } else {
            $this->addFlash('success', 'common.saved');
        }

        return $this->redirectToRoute('admin_sync');
    }

    /**
     * Remet en file une remontée abandonnée.
     *
     * Une par une, et non toutes d'un bloc : les abandons n'ont pas forcément
     * la même cause, et tout relancer sans regarder ferait repartir huit fois
     * la ligne qui ne passera jamais.
     */
    #[Route('/{id}/reprendre', name: 'admin_sync_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(int $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        if ($this->store->retrySync($id)) {
            $this->store->audit($admin->id, $admin->role->value, 'SYNC_RETRIED', null, null, ['entry' => $id]);
            $this->addFlash('success', 'common.saved');
        }

        return $this->redirectToRoute('admin_sync');
    }
}
