<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Domain\TaskAccess;
use Merisu\Inventory\Domain\TaskTile;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Service\PhotoStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d'entrée de la file d'attente hors-ligne (§2).
 *
 * Les écrans fonctionnent en formulaires HTML classiques ; quand le réseau
 * manque, le script `app.js` met l'envoi en file dans IndexedDB et le rejoue
 * ici, dans l'ordre, au retour de la connexion.
 *
 * L'écriture des comptages étant idempotente (clé date + poste + produit +
 * moment), rejouer un envoi déjà passé ne crée jamais de doublon.
 */
#[Route('/sync')]
final class SyncController extends AbstractController
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CurrentUser $currentUser,
        private readonly PhotoStorage $photos,
    ) {
    }

    #[Route('', name: 'sync_replay', methods: ['POST'])]
    public function replay(Request $request): JsonResponse
    {
        $consultant = $this->currentUser->consultant();

        // Session expirée pendant la coupure : le client doit reconnecter
        // l'utilisateur plutôt que de vider sa file.
        if ($consultant === null) {
            return new JsonResponse(['error' => 'MISSING_TOKEN'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'INVALID_PAYLOAD'], 400);
        }

        $moment = CountMoment::tryFrom((string) ($payload['moment'] ?? ''));
        $date = (string) ($payload['date'] ?? '');

        if ($moment === null || !BusinessDate::isValid($date)) {
            return new JsonResponse(['error' => 'INVALID_PAYLOAD'], 400);
        }

        /*
          La TUILE, comme à l'écran.

          Cette route rejoue exactement les écritures de la saisie ; la garder
          derrière la seule session en aurait fait le chemin de contournement
          du contrôle par tuile. Vérifié APRÈS avoir lu le moment, puisque
          c'est lui qui désigne la tuile — matin ou soir.

          Refus en 403 JSON et non par exception : le client hors-ligne doit
          pouvoir distinguer « reconnecte-toi » (401) de « cette file ne te
          revient pas » (403), et cesser de rejouer dans le second cas.
        */
        $tuile = $moment->isEvening() ? TaskTile::Evening : TaskTile::Morning;

        if (!TaskAccess::allows($consultant->tiles, $tuile, $consultant->role)) {
            return new JsonResponse(['error' => 'TILE_NOT_ALLOWED'], 403);
        }

        try {
            $workstationId = $this->currentUser->resolveWorkstation($payload['workstationId'] ?? null);

            $quantities = [];
            foreach ((array) ($payload['quantities'] ?? []) as $productId => $value) {
                $value = is_scalar($value) ? trim((string) $value) : '';
                $normalized = str_replace(',', '.', $value);
                $quantities[(string) $productId] = is_numeric($normalized) ? (float) $normalized : null;
            }

            $saved = $this->inventory->saveCounts(
                $date,
                $workstationId,
                $moment,
                $quantities,
                $consultant->id,
                $consultant->role,
            );

            // Photos différées : elles arrivent après les quantités, l'ordre de la
            // file garantit que le comptage existe déjà.
            $photos = 0;
            foreach ((array) ($payload['photos'] ?? []) as $photo) {
                if (!\is_array($photo) || !isset($photo['productId'], $photo['dataUrl'])) {
                    continue;
                }
                $this->inventory->addPhoto(
                    $date,
                    $workstationId,
                    (string) $photo['productId'],
                    $moment,
                    $this->photos->storeBase64((string) $photo['dataUrl']),
                    $consultant->id,
                    $consultant->role,
                );
                ++$photos;
            }

            $validated = false;
            if (($payload['validate'] ?? false) === true) {
                $outcome = $this->inventory->validate($date, $workstationId, $moment, $consultant->id, $consultant->role);
                $validated = $outcome['result']->valid;

                if (!$validated) {
                    return new JsonResponse([
                        'error' => 'VALIDATION_FAILED',
                        'issues' => array_map(
                            static fn ($i): array => ['code' => $i->code, 'productId' => $i->productId],
                            $outcome['result']->issues,
                        ),
                    ], 409);
                }
            }

            return new JsonResponse(['saved' => $saved, 'photos' => $photos, 'validated' => $validated]);
        } catch (\RuntimeException $e) {
            // Erreur métier : inutile de rejouer, le client retire l'envoi de sa file.
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }
    }
}
