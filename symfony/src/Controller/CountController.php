<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Service\PhotoStorage;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** §7.2 / §7.3 — Écrans de saisie du matin (08:00) et du soir (22:00). */
final class CountController extends AbstractController
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CurrentUser $currentUser,
        private readonly ConsultantServiceInterface $consultants,
        private readonly Store $store,
        private readonly PhotoStorage $photos,
    ) {
    }

    /**
     * Menu des tâches — écran d'accueil du poste.
     *
     * Le vendeur y voit ce qu'il lui reste à faire aujourd'hui, avec l'état de
     * chaque tâche (à faire / validée). La tâche correspondant à l'heure est
     * mise en avant : avant l'heure de clôture c'est le comptage d'ouverture,
     * après c'est celui de clôture.
     *
     * L'administration n'y figure pas : elle a sa propre porte d'entrée.
     */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        $this->currentUser->requireConsultant();

        $date = $this->inventory->today();
        $workstationId = $this->currentUser->resolveWorkstation();
        $suggested = $this->inventory->suggestedMoment();

        // Les horaires viennent des paramètres généraux : `daySheet()` ne les
        // renvoie pas, il expose l'état de la saisie.
        $settings = $this->store->settings();

        $morning = $this->inventory->daySheet($date, $workstationId, CountMoment::Open0800);
        $evening = $this->inventory->daySheet($date, $workstationId, CountMoment::Close2200);

        return $this->render('count/tasks.html.twig', [
            'date' => $date,
            'workstationId' => $workstationId,
            'workstations' => $this->consultants->workstations(),
            'suggested' => $suggested,
            'tasks' => [
                [
                    'route' => 'count_morning', 'icon' => 'sunrise',
                    'label' => 'nav.morning',
                    'title' => 'morning.title',
                    'time' => $settings->openingTime,
                    'done' => $morning['validated'],
                    'saved' => \count($morning['counts']),
                    'total' => \count($morning['products']),
                    'highlight' => !$suggested->isEvening(),
                ],
                [
                    'route' => 'count_evening', 'icon' => 'moon',
                    'label' => 'nav.evening',
                    'title' => 'evening.title',
                    'time' => $settings->closingTime,
                    'done' => $evening['validated'],
                    'saved' => \count($evening['counts']),
                    'total' => \count($evening['products']),
                    'highlight' => $suggested->isEvening(),
                ],
            ],
            // Le plan du jour vient du soir précédent : c'est une consultation,
            // pas une saisie, d'où sa présentation distincte.
            'planForToday' => $morning['planForToday'],
        ]);
    }

    #[Route('/saisie/matin', name: 'count_morning', methods: ['GET'])]
    public function morning(Request $request): Response
    {
        return $this->renderSheet($request, CountMoment::Open0800);
    }

    #[Route('/saisie/soir', name: 'count_evening', methods: ['GET'])]
    public function evening(Request $request): Response
    {
        return $this->renderSheet($request, CountMoment::Close2200);
    }

    private function renderSheet(Request $request, CountMoment $moment): Response
    {
        $this->currentUser->requireConsultant();

        $date = $this->resolveDate($request);
        $workstationId = $this->currentUser->resolveWorkstation($request->query->get('workstationId'));

        $sheet = $this->inventory->daySheet($date, $workstationId, $moment);

        return $this->render('count/sheet.html.twig', $sheet + [
            'workstations' => $this->consultants->workstations(),
            // Plan tout juste figé, transmis via la session après validation (§3.2.4).
            'freshPlan' => $request->getSession()->remove('merisu.fresh_plan') ?? [],
            'issues' => $request->getSession()->remove('merisu.issues') ?? [],
        ]);
    }

    /**
     * Enregistrement des quantités.
     * Idempotent : rejouer le même envoi (file hors-ligne) ne crée pas de doublon.
     */
    #[Route('/saisie/{moment}/enregistrer', name: 'count_save', methods: ['POST'])]
    public function save(Request $request, string $moment): Response
    {
        $consultant = $this->currentUser->requireConsultant();
        $countMoment = $this->parseMoment($moment);

        $date = $this->resolveDate($request);
        $workstationId = $this->currentUser->resolveWorkstation($request->request->get('workstationId'));

        try {
            $saved = $this->inventory->saveCounts(
                $date,
                $workstationId,
                $countMoment,
                $this->parseQuantities($request),
                $consultant->id,
                $consultant->role,
            );
            $this->addFlash('success', $saved > 0 ? 'common.saved' : 'errors.NO_ENTRIES');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'errors.' . $e->getMessage());
        }

        return $this->redirectToSheet($countMoment, $date);
    }

    /**
     * Validation. Pour le soir : verrouille les saisies et fige le plan du lendemain,
     * affiché immédiatement à l'écran (§3.2.4).
     */
    #[Route('/saisie/{moment}/valider', name: 'count_validate', methods: ['POST'])]
    public function validate(Request $request, string $moment): Response
    {
        $consultant = $this->currentUser->requireConsultant();
        $countMoment = $this->parseMoment($moment);

        $date = $this->resolveDate($request);
        $workstationId = $this->currentUser->resolveWorkstation($request->request->get('workstationId'));

        // Le consultant a pu modifier un champ sans passer par « Enregistrer ».
        try {
            $this->inventory->saveCounts(
                $date,
                $workstationId,
                $countMoment,
                $this->parseQuantities($request),
                $consultant->id,
                $consultant->role,
            );
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'errors.' . $e->getMessage());

            return $this->redirectToSheet($countMoment, $date);
        }

        $outcome = $this->inventory->validate($date, $workstationId, $countMoment, $consultant->id, $consultant->role);

        if (!$outcome['result']->valid) {
            $this->addFlash('error', 'errors.VALIDATION_FAILED');
            $request->getSession()->set('merisu.issues', array_map(
                static fn ($issue): array => ['code' => $issue->code, 'productId' => $issue->productId],
                $outcome['result']->issues,
            ));

            return $this->redirectToSheet($countMoment, $date);
        }

        $this->addFlash('success', 'common.saved');

        if ($outcome['plan'] !== []) {
            $request->getSession()->set('merisu.fresh_plan', $outcome['plan']);
        }

        return $this->redirectToSheet($countMoment, $date);
    }

    /** Ajout d'une photo (preuve du stock du soir). */
    #[Route('/saisie/{moment}/photo', name: 'count_photo', methods: ['POST'])]
    public function photo(Request $request, string $moment): Response
    {
        $consultant = $this->currentUser->requireConsultant();
        $countMoment = $this->parseMoment($moment);

        $date = $this->resolveDate($request);
        $workstationId = $this->currentUser->resolveWorkstation($request->request->get('workstationId'));
        $productId = (string) $request->request->get('productId');
        $file = $request->files->get('photo');

        try {
            if ($file === null) {
                throw new \RuntimeException('MISSING_PHOTO_DATA');
            }

            $this->inventory->addPhoto(
                $date,
                $workstationId,
                $productId,
                $countMoment,
                $this->photos->store($file),
                $consultant->id,
                $consultant->role,
            );
            $this->addFlash('success', 'common.saved');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'errors.' . $e->getMessage());
        }

        return $this->redirectToSheet($countMoment, $date);
    }

    /** §7.4 — Écran « À produire » : plan figé pour une date donnée. */
    #[Route('/a-produire', name: 'production', methods: ['GET'])]
    public function production(Request $request): Response
    {
        $this->currentUser->requireConsultant();

        $workstationId = $this->currentUser->resolveWorkstation($request->query->get('workstationId'));
        $today = $this->inventory->today();

        $forDate = (string) $request->query->get('forDate', BusinessDate::next($today));
        if (!BusinessDate::isValid($forDate)) {
            $forDate = BusinessDate::next($today);
        }

        $products = [];
        foreach ($this->store->products() as $product) {
            $products[$product->id] = $product;
        }

        return $this->render('count/production.html.twig', [
            'forDate' => $forDate,
            'dayOfWeek' => BusinessDate::dayOfWeek($forDate),
            'computedFromDate' => BusinessDate::previous($forDate),
            'today' => $today,
            'tomorrow' => BusinessDate::next($today),
            'lines' => $this->store->plan($forDate, $workstationId),
            'products' => $products,
            'workstations' => $this->consultants->workstations(),
        ]);
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    /** @return array<string, float|null> */
    private function parseQuantities(Request $request): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $request->request->all('qty');
        $quantities = [];

        foreach ($raw as $productId => $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';

            // Un champ vide n'est PAS un zéro : c'est une absence de saisie.
            if ($value === '') {
                $quantities[(string) $productId] = null;
                continue;
            }

            $normalized = str_replace(',', '.', $value);
            $quantities[(string) $productId] = is_numeric($normalized) ? (float) $normalized : null;
        }

        return $quantities;
    }

    private function resolveDate(Request $request): string
    {
        $date = (string) ($request->request->get('date') ?? $request->query->get('date', ''));

        return BusinessDate::isValid($date) ? $date : $this->inventory->today();
    }

    private function parseMoment(string $moment): CountMoment
    {
        return match ($moment) {
            'matin' => CountMoment::Open0800,
            'soir' => CountMoment::Close2200,
            default => throw $this->createNotFoundException('INVALID_MOMENT'),
        };
    }

    private function redirectToSheet(CountMoment $moment, string $date): Response
    {
        return $this->redirectToRoute(
            $moment->isEvening() ? 'count_evening' : 'count_morning',
            ['date' => $date],
        );
    }
}
