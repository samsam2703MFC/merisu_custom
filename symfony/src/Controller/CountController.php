<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\ContainerQuantity;
use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Domain\ProductionGate;
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
        private readonly Store $store,
        private readonly PhotoStorage $photos,
        private readonly ChecklistController $checklist,
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
        $checklist = $this->checklist->progress($date, $workstationId);

        // Le plan qui compte pour la tuile « À produire », c'est celui de
        // DEMAIN : c'est lui qu'on prépare, celui d'aujourd'hui est déjà en
        // cours. D'où J+1 ici comme sur l'écran.
        $tomorrow = BusinessDate::next($date);
        $planForTomorrow = $this->store->plan($tomorrow, $workstationId);
        $toProduce = \count(array_filter($planForTomorrow, static fn ($l): bool => $l->qtyToProduce > 0));
        $stop = $this->store->activeStop($workstationId);

        return $this->render('count/tasks.html.twig', [
            'date' => $date,
            'workstationId' => $workstationId,
            'suggested' => $suggested,
            'tasks' => [
                [
                    'route' => 'count_morning', 'icon' => 'sunrise',
                    'label' => 'nav.morning',
                    'progressKey' => 'tasks.inProgress',
                    'todoKey' => 'tasks.todo',
                    'doneKey' => 'tasks.validated',
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
                    'progressKey' => 'tasks.inProgress',
                    'todoKey' => 'tasks.todo',
                    'doneKey' => 'tasks.validated',
                    'title' => 'evening.title',
                    'time' => $settings->closingTime,
                    'done' => $evening['validated'],
                    'saved' => \count($evening['counts']),
                    'total' => \count($evening['products']),
                    'highlight' => $suggested->isEvening(),
                ],
                // La check-list n'a pas d'heure : elle accompagne la journée
                // entière, d'où l'absence de `time` et de mise en avant.
                [
                    'route' => 'checklist', 'icon' => 'checklist',
                    'label' => 'nav.checklist',
                    // « 4 sur 6 produits saisis » n'aurait aucun sens ici :
                    // une check-list compte des points, pas des produits.
                    'progressKey' => 'tasks.checklistProgress',
                    'todoKey' => 'tasks.checklistTodo',
                    // « Saisie verrouillée » ne veut rien dire pour une
                    // check-list : elle se coche et se décoche toute la
                    // journée, rien ne s'y verrouille.
                    'doneKey' => 'tasks.checklistDone',
                    'title' => 'checklist.title',
                    'time' => null,
                    'done' => $checklist['complete'],
                    'saved' => $checklist['done'],
                    'total' => $checklist['total'],
                    'highlight' => false,
                ],
            ],
            // Le plan du jour vient du soir précédent : c'est une consultation,
            // pas une saisie, d'où sa présentation distincte.
            'planForToday' => $morning['planForToday'],
            // Tuile « À produire (J+1) » : violette, et son icône suit l'état.
            // Un pictogramme figé n'apprend rien ; celui-ci dit, avant même la
            // lecture, s'il y a du travail, s'il n'y en a pas, ou si tout est
            // à l'arrêt.
            'produce' => [
                'forDate' => $tomorrow,
                'count' => $toProduce,
                'icon' => match (true) {
                    $stop !== null => 'stop',
                    $toProduce > 0 => 'tray-full',
                    default => 'tray',
                },
            ],
            'stop' => $stop,
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

    /**
     * §7.4 — Écran « À produire » : plan figé pour une date donnée.
     *
     * Par défaut J+1 : c'est la production de demain qu'on prépare ce soir.
     *
     * Deux verrous peuvent s'y opposer, et ils ne disent pas la même chose.
     * L'arrêt de production est une décision : on ne produit plus, point.
     * La check-list est une condition : on ne produit pas ENCORE, il reste des
     * points obligatoires à cocher. Les deux masquent la liste plutôt que de
     * l'afficher barrée — une liste visible finit toujours par être suivie.
     */
    #[Route('/a-produire', name: 'production', methods: ['GET'])]
    public function production(Request $request): Response
    {
        $this->currentUser->requireConsultant();

        $view = $this->productionView($request);

        return $this->render('count/production.html.twig', $view);
    }

    /**
     * Étiquettes de production, prêtes à imprimer.
     *
     * Une page à part, sans navigation : ce qui sort de l'imprimante ne doit
     * porter que les étiquettes. Elle suit le filtre par catégorie de l'écran
     * précédent — on imprime ce qu'on regarde, sinon la planche d'étiquettes ne
     * correspond plus à la liste qu'on a sous les yeux.
     *
     * Sans JavaScript, la page reste imprimable par le navigateur : le bouton
     * n'est qu'un raccourci.
     */
    #[Route('/a-produire/etiquettes', name: 'production_labels', methods: ['GET'])]
    public function labels(Request $request): Response
    {
        $consultant = $this->currentUser->requireConsultant();

        $view = $this->productionView($request);

        // Les verrous valent aussi ici : sans cela, l'impression contournerait
        // l'arrêt de production et la check-list d'un simple lien.
        if (!ProductionGate::allows($view['stop'], $view['blocking'])) {
            return $this->redirectToRoute('production', [
                'forDate' => $view['forDate'],
                'category' => $view['category'],
            ]);
        }

        return $this->render('count/labels.html.twig', $view + [
            'printedBy' => $consultant->displayName(),
        ]);
    }

    /**
     * État commun à l'écran « À produire » et à sa planche d'étiquettes.
     *
     * @return array<string, mixed>
     */
    private function productionView(Request $request): array
    {
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

        $category = trim((string) $request->query->get('category', ''));
        $lines = $this->store->plan($forDate, $workstationId);

        if ($category !== '') {
            $lines = array_values(array_filter(
                $lines,
                static fn ($line): bool => ($products[$line->productId] ?? null)?->category === $category,
            ));
        }

        return [
            'forDate' => $forDate,
            'dayOfWeek' => BusinessDate::dayOfWeek($forDate),
            'computedFromDate' => BusinessDate::previous($forDate),
            'today' => $today,
            'tomorrow' => BusinessDate::next($today),
            'workstationId' => $workstationId,
            'lines' => $lines,
            'products' => $products,
            'category' => $category,
            // Catégories réellement portées par les produits : la liste ne se
            // configure nulle part, elle se déduit. Aucune donnée en dur (§2).
            'categories' => $this->categories($products),
            'stop' => $this->store->activeStop($workstationId),
            'blocking' => $this->checklist->blockingItems($today, $workstationId),
        ];
    }

    /**
     * @param array<string, \Merisu\Inventory\Domain\Product> $products
     *
     * @return list<string>
     */
    private function categories(array $products): array
    {
        $found = [];

        foreach ($products as $product) {
            if ($product->active && $product->category !== '') {
                $found[$product->category] = true;
            }
        }

        $names = array_keys($found);
        sort($names, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $names;
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    /** @return array<string, float|null> */
    private function parseQuantities(Request $request): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $request->request->all('qty');
        /** @var array<string, mixed> $fractions Fractions du contenant entamé. */
        $fractions = $request->request->all('frac');
        $quantities = [];

        foreach ($raw as $productId => $value) {
            $productId = (string) $productId;
            $value = is_scalar($value) ? trim((string) $value) : '';

            // Produit compté en contenants : deux champs à recomposer. Le
            // découpage n'existe qu'à l'écran ; la base ne connaît qu'une
            // quantité décimale, et tous les calculs restent inchangés.
            if (\array_key_exists($productId, $fractions)) {
                $percent = is_scalar($fractions[$productId]) ? (int) $fractions[$productId] : 0;

                // Ni contenant plein ni fraction : rien n'a été compté. Un
                // contenant entamé seul, lui, est bien une saisie.
                if ($value === '' && $percent === 0) {
                    $quantities[$productId] = null;
                    continue;
                }

                $quantities[$productId] = ContainerQuantity::combine(
                    $value === '' ? 0 : (int) $value,
                    $percent,
                );
                continue;
            }

            // Un champ vide n'est PAS un zéro : c'est une absence de saisie.
            if ($value === '') {
                $quantities[$productId] = null;
                continue;
            }

            $normalized = str_replace(',', '.', $value);
            $quantities[$productId] = is_numeric($normalized) ? (float) $normalized : null;
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
