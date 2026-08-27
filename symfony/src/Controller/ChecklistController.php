<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\Checklist;
use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ChecklistSignature;
use Merisu\Inventory\Domain\ChecklistStatus;
use Merisu\Inventory\Domain\TaskTile;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Security\PinField;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Service\PhotoStorage;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Check-list du poste : ouverture, fermeture, contrôle qualité.
 *
 * Elle accompagne les deux comptages sans s'y mêler. Un comptage se valide et
 * se verrouille — c'est une donnée chiffrée qui alimente le plan de production.
 * Une check-list se reprend tant que la journée dure : un point oublié doit
 * pouvoir être rattrapé, et un point mal jugé repris.
 *
 * ── Signature point par point ───────────────────────────────────────────────
 *
 * Chaque point est signé SÉPARÉMENT, par un code PIN saisi au moment où il est
 * traité. C'est ce qui change tout par rapport à un formulaire enregistré en
 * bloc : si Marco ouvre la session à 8 h et que Claire traite trois points à
 * 11 h, l'historique portait « Marco » sur les trois. Il porte désormais le nom
 * de qui a réellement agi — ce qui est le but même d'une check-list d'hygiène.
 *
 * Le code suffit à désigner la personne : il est unique par consultant, comme
 * à la connexion. Aucun sélecteur de nom, donc aucun moyen de signer d'un nom
 * qui ne serait pas le sien.
 */
final class ChecklistController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly InventoryService $inventory,
        private readonly Store $store,
        private readonly ConsultantServiceInterface $consultants,
        private readonly PhotoStorage $photos,
        /**
         * Le même limiteur que la connexion.
         *
         * La reconnaissance d'un code est une porte : sans limite, on
         * essaierait le million de combinaisons à six chiffres pour retrouver
         * les codes de l'équipe. Le limiteur de connexion protège déjà
         * exactement cette surface, il n'y a pas lieu d'en inventer un second.
         */
        private readonly RateLimiterFactory $loginIpLimiter,
    ) {
    }

    // ── Vue d'entrée : les volets et leur avancement ─────────────────────────

    #[Route('/check-list', name: 'checklist', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $this->currentUser->requireTile(TaskTile::Checklist);

        $aujourdhui = $this->inventory->today();
        $date = self::pastDate($request, $aujourdhui);
        $workstationId = $this->currentUser->resolveWorkstation();

        return $this->render('count/checklist.html.twig', [
            'date' => $date,
            'today' => $aujourdhui,
            'workstationId' => $workstationId,
            'sections' => $this->sections($date, $workstationId),
        ]);
    }

    // ── Les points d'un volet ────────────────────────────────────────────────

    #[Route('/check-list/{section}', name: 'checklist_section', methods: ['GET'])]
    public function section(Request $request, string $section): Response
    {
        $this->currentUser->requireTile(TaskTile::Checklist);

        $voulu = strtoupper(trim($section));

        $aujourdhui = $this->inventory->today();
        $date = self::pastDate($request, $aujourdhui);
        $workstationId = $this->currentUser->resolveWorkstation();

        foreach ($this->sections($date, $workstationId) as $bloc) {
            if ($bloc['checklist']->id === $voulu) {
                return $this->render('count/checklist_section.html.twig', $bloc + [
                    'date' => $date,
                    'today' => $aujourdhui,
                    'workstationId' => $workstationId,
                ]);
            }
        }

        throw $this->createNotFoundException();
    }

    // ── Signature d'un point ─────────────────────────────────────────────────

    #[Route('/check-list/point/{itemId}', name: 'checklist_point', methods: ['GET'])]
    public function point(string $itemId): Response
    {
        $this->currentUser->requireTile(TaskTile::Checklist);

        $item = $this->store->checklistItem($itemId);
        if ($item === null || !$item->active) {
            throw $this->createNotFoundException();
        }

        $date = $this->inventory->today();
        $workstationId = $this->currentUser->resolveWorkstation();
        $entry = $this->store->checklistEntries($date, $workstationId)[$itemId] ?? null;

        // La fiche de la check-list, pour nommer le volet en tête : son nom
        // est une donnée, il ne se déduit plus d'une clé de traduction.
        $liste = null;
        foreach ($this->store->checklists() as $candidate) {
            if ($candidate->id === $item->checklistId) {
                $liste = $candidate;
            }
        }

        return $this->render('count/checklist_point.html.twig', [
            'item' => $item,
            'checklist' => $liste,
            'entry' => $entry,
            'date' => $date,
            'signedBy' => $entry === null ? null : $this->consultants->consultant($entry->consultantId)?->displayName(),
        ]);
    }

    #[Route('/check-list/point/{itemId}', name: 'checklist_point_save', methods: ['POST'])]
    public function savePoint(Request $request, string $itemId): Response
    {
        $this->currentUser->requireTile(TaskTile::Checklist);

        $item = $this->store->checklistItem($itemId);
        if ($item === null || !$item->active) {
            throw $this->createNotFoundException();
        }

        $date = $this->inventory->today();
        $workstationId = $this->currentUser->resolveWorkstation();

        // Le limiteur s'applique AVANT de regarder le code : sans cela, il
        // suffirait de compter les réponses pour distinguer un code refusé
        // d'un code inconnu.
        if (!$this->loginIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $this->store->audit('anonyme', 'ANONYMOUS', 'CHECKLIST_PIN_THROTTLED', $workstationId, $date, [
                'itemId' => $itemId,
            ]);

            return $this->refus($itemId, 'signature.throttled');
        }

        // L'entrée du jour, lue UNE fois : elle sert à trois questions —
        // une photo existe-t-elle déjà, faut-il en exiger une, laquelle
        // conserver — et trois lectures posaient trois fois la même requête.
        $existante = $this->store->checklistEntries($date, $workstationId)[$itemId] ?? null;

        $signataire = $this->consultants->authenticateByPin(PinField::read($request));
        $status = ChecklistStatus::tryFromLoose($request->request->get('status'));
        $note = trim((string) $request->request->get('note', ''));

        $photo = $request->files->get('photo');
        $photoFournie = $photo instanceof UploadedFile && $photo->isValid();

        $verdict = ChecklistSignature::check(
            $item,
            $status ?? ChecklistStatus::Pending,
            $signataire !== null,
            $note === '' ? null : $note,
            // Une photo déjà enregistrée compte : reprendre un point pour en
            // corriger la note ne doit pas obliger à la reprendre.
            $photoFournie || $existante?->photoPath !== null,
        );

        if (!$verdict->isValid() || $signataire === null || $status === null) {
            return $this->refus($itemId, $verdict->issues[0] ?? 'signature.unknownPin');
        }

        $chemin = $existante?->photoPath;

        if ($photoFournie) {
            try {
                $chemin = $this->photos->store($photo);
            } catch (\RuntimeException) {
                return $this->refus($itemId, 'signature.photoRejected');
            }
        }

        $this->store->setChecklistEntry(
            $date,
            $workstationId,
            $itemId,
            $status,
            // L'auteur est celui du CODE, pas celui de la session : c'est
            // toute la différence entre « qui était connecté » et « qui a
            // réellement fait le point ».
            $signataire->id,
            $note === '' ? null : $note,
            $chemin,
        );

        $this->store->audit(
            $signataire->id,
            $signataire->role->value,
            'CHECKLIST_POINT_' . $status->value,
            $workstationId,
            $date,
            ['itemId' => $itemId, 'hasPhoto' => $chemin !== null],
        );

        $this->addFlash('success', 'checklist.signed');

        return $this->redirectToRoute('checklist_section', ['section' => $item->checklistId]);
    }

    /**
     * Reconnaissance du code, pendant la frappe : renvoie le nom, rien d'autre.
     *
     * Sert à confirmer À LA PERSONNE qu'elle a bien tapé son code, avant
     * qu'elle n'engage sa signature. Sans ce retour, une faute de frappe ne se
     * découvre qu'après coup, sur un point signé au nom d'un collègue.
     *
     * Ne renvoie QUE le nom affiché : ni identifiant, ni rôle, ni poste. Et
     * passe par le limiteur de connexion, car c'est bien une porte.
     */
    #[Route('/check-list/reconnaitre', name: 'checklist_identify', methods: ['POST'])]
    public function identify(Request $request): JsonResponse
    {
        // Une simple reconnaissance de code, PARTAGÉE : l'écran de signature
        // du plan de production s'en sert aussi. L'exiger sous la tuile
        // check-list aurait privé de son retour de frappe quelqu'un qui a le
        // droit de produire mais pas celui de cocher la check-list.
        $this->currentUser->requireConsultant();

        if (!$this->loginIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['name' => null], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $consultant = $this->consultants->authenticateByPin(PinField::read($request));

        return new JsonResponse(['name' => $consultant?->displayName()]);
    }

    // ── Utilitaires ──────────────────────────────────────────────────────────

    private function refus(string $itemId, string $cle): Response
    {
        $this->addFlash('error', $cle);

        return $this->redirectToRoute('checklist_point', ['itemId' => $itemId]);
    }

    /**
     * Les trois volets, chacun avec ses points et son avancement.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * La journée regardée : aujourd'hui, ou un jour PASSÉ qu'on relit.
     *
     * Le futur est refusé — une check-list de demain n'a rien à montrer, et
     * l'ouvrir laisserait croire qu'on peut la préparer d'avance. Une date
     * illisible retombe sur aujourd'hui plutôt que d'échouer : on vient
     * pointer, pas déboguer une URL.
     *
     * SIGNER reste réservé à aujourd'hui, et c'est le gabarit qui l'applique :
     * ces lignes sont des pièces d'audit, et antidater une signature leur
     * ôterait toute valeur de preuve.
     */
    private static function pastDate(Request $request, string $today): string
    {
        $demande = trim((string) $request->query->get('date', ''));

        if ($demande === '' || !BusinessDate::isValid($demande) || $demande > $today) {
            return $today;
        }

        return $demande;
    }

    private function sections(string $date, string $workstationId): array
    {
        $items = $this->store->checklistItems(true);
        $entries = $this->store->checklistEntries($date, $workstationId);

        $sections = [];

        foreach ($this->store->checklists(true) as $liste) {
            $ofSection = array_values(array_filter(
                $items,
                static fn (ChecklistItem $i): bool => $i->checklistId === $liste->id,
            ));

            $lignes = [];
            $obligatoires = 0;
            $obligatoiresTraites = 0;

            foreach ($ofSection as $item) {
                $entry = $entries[$item->id] ?? null;
                $status = $entry?->status ?? ChecklistStatus::Pending;

                if ($item->required) {
                    ++$obligatoires;
                    if ($status->isSettled()) {
                        ++$obligatoiresTraites;
                    }
                }

                $lignes[] = [
                    'item' => $item,
                    'status' => $status,
                    'note' => $entry?->note,
                    'photoPath' => $entry?->photoPath,
                    'by' => $entry === null ? null : $this->consultants->consultant($entry->consultantId)?->displayName(),
                    'at' => $status->isSettled() ? $entry?->checkedAt : null,
                ];
            }

            $faits = \count(array_filter(
                $lignes,
                static fn (array $l): bool => $l['status'] === ChecklistStatus::Done,
            ));

            $sections[] = [
                'checklist' => $liste,
                /*
                  L'heure vient de la CHECK-LIST elle-même, plus des paramètres
                  généraux. C'était leur source quand les volets étaient figés ;
                  une liste que l'administrateur crée porte la sienne, et les
                  trois historiques ont hérité de celle des paramètres au
                  moment de l'amorçage.
                */
                'time' => $liste->hasExecutionTime() ? $liste->executionTime : null,
                'rows' => $lignes,
                'total' => \count($lignes),
                'done' => $faits,
                'failed' => \count(array_filter(
                    $lignes,
                    static fn (array $l): bool => $l['status']->isProblem(),
                )),
                'percent' => $lignes === [] ? 0 : (int) round($faits / \count($lignes) * 100),
                // « Complet » se juge sur les seuls points obligatoires, et un
                // point PASSÉ compte comme traité : la personne l'a examiné.
                'complete' => $obligatoires > 0 && $obligatoiresTraites === $obligatoires,
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
            $status = ($entries[$item->id] ?? null)?->status ?? ChecklistStatus::Pending;

            if ($status === ChecklistStatus::Done) {
                ++$done;
            }

            if (!$status->isSettled() && $item->required) {
                ++$manquants;
            }
        }

        return ['done' => $done, 'total' => $total, 'complete' => $total > 0 && $manquants === 0];
    }
}
