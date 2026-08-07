<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Adapter\InventorySyncInterface;
use Merisu\Inventory\Adapter\SyncUnavailable;
use Merisu\Inventory\Store\Store;

/**
 * Vide la file d'envoi vers le système hôte.
 *
 * Appelé hors requête — par `merisu:synchroniser`, qu'une tâche planifiée
 * déclenche. Jamais pendant la validation d'un comptage : le comptoir ne doit
 * pas attendre un service distant pour clôturer sa journée.
 */
final class SyncService
{
    public function __construct(
        private readonly Store $store,
        private readonly InventorySyncInterface $sync,
    ) {
    }

    /**
     * @return array{sent: int, retried: int, failed: int, skipped: int}
     */
    public function drain(int $limit = 50): array
    {
        /*
          Rien n'est tenté tant qu'aucune implémentation réelle n'est branchée.

          Sans ce garde-fou, les huit tentatives de chaque ligne s'épuiseraient
          contre un adaptateur muet, et des comptages bien réels seraient
          marqués « en échec » avant même que l'intégration existe.
        */
        if (!$this->sync->isConfigured()) {
            return ['sent' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => \count($this->store->dueSync($limit))];
        }

        $envoyes = $reessayer = $echoues = 0;

        foreach ($this->store->dueSync($limit) as $entree) {
            try {
                $this->sync->send($entree);
                $this->store->markSyncSent($entree->id);
                ++$envoyes;
            } catch (SyncUnavailable $e) {
                // Panne passagère : on réessaiera, avec du recul.
                $this->store->markSyncFailed($entree->id, $e->getMessage(), $entree->attempts + 1, false);
                ++$reessayer;
            } catch (\Throwable $e) {
                // Refus définitif : réessayer huit fois une requête que l'hôte
                // a raison de refuser ne fait que retarder le moment où
                // quelqu'un la regarde.
                $this->store->markSyncFailed($entree->id, $e->getMessage(), $entree->attempts + 1, true);
                ++$echoues;
            }
        }

        return ['sent' => $envoyes, 'retried' => $reessayer, 'failed' => $echoues, 'skipped' => 0];
    }
}
