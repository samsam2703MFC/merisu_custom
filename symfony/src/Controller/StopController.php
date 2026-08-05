<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Arrêt de production et de prise de commande.
 *
 * Four en panne, rupture de matière, contrôle sanitaire : il faut pouvoir
 * dire « on ne produit plus » tout de suite, depuis le poste, sans passer par
 * l'administration. L'arrêt bloque la production et l'impression d'étiquettes ;
 * il NE bloque PAS les comptages. Compter le stock pendant un arrêt reste utile
 * — c'est même souvent la première chose à faire.
 *
 * Le motif est obligatoire. Un arrêt sans motif ne s'explique pas le lendemain,
 * et c'est justement le lendemain qu'on cherche à comprendre.
 */
final class StopController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly InventoryService $inventory,
        private readonly Store $store,
    ) {
    }

    #[Route('/arret', name: 'stop', methods: ['GET'])]
    public function show(): Response
    {
        $this->currentUser->requireConsultant();

        $workstationId = $this->currentUser->resolveWorkstation();

        return $this->render('count/stop.html.twig', [
            'workstationId' => $workstationId,
            'active' => $this->store->activeStop($workstationId),
            'history' => $this->store->stops($workstationId, 10),
        ]);
    }

    #[Route('/arret/declencher', name: 'stop_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $consultant = $this->currentUser->requireConsultant();

        $workstationId = $this->currentUser->resolveWorkstation();
        $reason = mb_substr(trim((string) $request->request->get('reason', '')), 0, 240);

        if ($reason === '') {
            $this->addFlash('error', 'errors.STOP_REASON_REQUIRED');

            return $this->redirectToRoute('stop');
        }

        // `startStop()` ne fait rien si un arrêt court déjà : deux vendeurs qui
        // appuient en même temps ne créent pas deux arrêts concurrents.
        if ($this->store->activeStop($workstationId) !== null) {
            return $this->redirectToRoute('stop');
        }

        $this->store->startStop($workstationId, $reason, $consultant->id);

        // §4 — qui, quand, où, pourquoi.
        $this->store->audit(
            $consultant->id,
            $consultant->role->value,
            'PRODUCTION_STOPPED',
            $workstationId,
            $this->inventory->today(),
            ['reason' => $reason],
        );

        $this->addFlash('success', 'stop.startedFlash');

        return $this->redirectToRoute('stop');
    }

    #[Route('/arret/lever', name: 'stop_lift', methods: ['POST'])]
    public function lift(): Response
    {
        $consultant = $this->currentUser->requireConsultant();

        $workstationId = $this->currentUser->resolveWorkstation();
        $active = $this->store->activeStop($workstationId);

        if ($active === null) {
            return $this->redirectToRoute('stop');
        }

        $this->store->liftStop($workstationId, $consultant->id);

        $this->store->audit(
            $consultant->id,
            $consultant->role->value,
            'PRODUCTION_RESUMED',
            $workstationId,
            $this->inventory->today(),
            ['reason' => $active->reason],
        );

        $this->addFlash('success', 'stop.liftedFlash');

        return $this->redirectToRoute('stop');
    }
}
