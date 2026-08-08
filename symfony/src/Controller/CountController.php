<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\ContainerQuantity;
use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Domain\LabelSheet;
use Merisu\Inventory\Domain\ProductionProgress;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Security\PinField;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Service\PhotoStorage;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
        private readonly ConsultantServiceInterface $consultants,
        /**
         * Le même limiteur que la connexion et la check-list.
         *
         * Signer une ligne de production, c'est présenter un code : c'est donc
         * une porte, et elle donne sur le même million de combinaisons à six
         * chiffres. En inventer un second l'aurait ouverte d'autant.
         */
        private readonly RateLimiterFactory $loginIpLimiter,
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
            // Un pictogramme figé n'apprend rien ; la cagette chargée dit qu'il
            // y a du travail avant même qu'on ait lu la ligne.
            // Consignes de marque, rédigées en administration : le module
            // n'en connaît aucune, il les affiche.
            'dayNotes' => $this->store->dayNotes(true),
            'produce' => [
                'forDate' => $tomorrow,
                'count' => $toProduce,
                'icon' => $toProduce > 0 ? 'tray-full' : 'tray',
            ],
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
     * Rien ne s'y oppose : la tuile mène droit à la liste. Un verrou de
     * check-list y a vécu un temps ; il transformait un écran de consultation
     * en obstacle, alors que la production se prépare le soir et que la
     * check-list se coche au fil de la journée.
     */
    #[Route('/a-produire', name: 'production', methods: ['GET'])]
    public function production(Request $request): Response
    {
        $this->currentUser->requireConsultant();

        $view = $this->productionView($request);

        return $this->render('count/production.html.twig', $view);
    }

    /**
     * Signature d'une ligne du plan : « c'est fait, et c'est moi ».
     *
     * Un écran par ligne, exactement comme un point de check-list, et pour la
     * même raison : le code PIN désigne QUI a produit, pas qui était connecté.
     * Marco ouvre la session à 8 h, Claire monte les verrines à 11 h — sans
     * cette distinction, l'historique attribue le travail de Claire à Marco.
     *
     * Une case à cocher ordinaire aurait suffi si l'on s'était contenté de
     * « quelqu'un l'a fait ». C'est justement ce qu'on ne veut pas.
     */
    #[Route('/a-produire/{productId}/fait', name: 'production_done', methods: ['GET'])]
    public function productionDone(Request $request, string $productId): Response
    {
        $this->currentUser->requireConsultant();

        [$line, $forDate, $workstationId] = $this->planLine($request, $productId);

        $entry = $this->store->productionEntries($forDate, $workstationId)[$productId] ?? null;

        return $this->render('count/production_point.html.twig', [
            'product' => $this->store->product($productId),
            'line' => $line,
            'entry' => $entry,
            'forDate' => $forDate,
            'category' => trim((string) $request->query->get('category', '')),
            'doneBy' => $entry === null ? null : $this->consultants->consultant($entry->consultantId)?->displayName(),
        ]);
    }

    /**
     * Coche — ou décoche — la ligne, au nom du code saisi.
     *
     * Le décochage passe par le MÊME code : une ligne signée ne se retire pas
     * d'un simple clic, sans quoi la signature ne vaudrait rien. L'audit garde
     * la trace des deux gestes, y compris de celui qui efface.
     */
    #[Route('/a-produire/{productId}/fait', name: 'production_done_save', methods: ['POST'])]
    public function saveProductionDone(Request $request, string $productId): Response
    {
        $this->currentUser->requireConsultant();

        [$line, $forDate, $workstationId] = $this->planLine($request, $productId);

        $category = trim((string) $request->request->get('category', ''));
        $retourEcran = ['forDate' => $forDate, 'category' => $category];
        $retourSignature = $retourEcran + ['productId' => $productId];

        // Le limiteur AVANT de regarder le code : sans cela, il suffirait de
        // compter les réponses pour distinguer un code refusé d'un inconnu.
        if (!$this->loginIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $this->store->audit('anonyme', 'ANONYMOUS', 'PRODUCTION_PIN_THROTTLED', $workstationId, $forDate, [
                'productId' => $productId,
            ]);
            $this->addFlash('error', 'signature.throttled');

            return $this->redirectToRoute('production_done', $retourSignature);
        }

        $signataire = $this->consultants->authenticateByPin(PinField::read($request));

        if ($signataire === null) {
            $this->addFlash('error', 'signature.unknownPin');

            return $this->redirectToRoute('production_done', $retourSignature);
        }

        $annule = $request->request->get('action') === 'undo';

        if ($annule) {
            $this->store->clearProductionDone($forDate, $workstationId, $productId);
        } else {
            $this->store->markProductionDone($forDate, $workstationId, $productId, $line->qtyToProduce, $signataire->id);
        }

        $this->store->audit(
            $signataire->id,
            $signataire->role->value,
            $annule ? 'PRODUCTION_UNDONE' : 'PRODUCTION_DONE',
            $workstationId,
            $forDate,
            ['productId' => $productId, 'qty' => $line->qtyToProduce],
        );

        $this->addFlash('success', $annule ? 'produce.undone' : 'produce.signed');

        return $this->redirectToRoute('production', $retourEcran);
    }

    /**
     * La ligne de plan visée, ou 404.
     *
     * Le produit doit figurer AU PLAN du jour demandé : signer une ligne qui
     * n'y est pas reviendrait à attester d'un travail que rien ne demandait,
     * et l'écran suivant ne la montrerait même pas.
     *
     * @return array{\Merisu\Inventory\Domain\ProductionPlanRow, string, string}
     */
    private function planLine(Request $request, string $productId): array
    {
        $workstationId = $this->currentUser->resolveWorkstation($request->query->get('workstationId'));
        $today = $this->inventory->today();

        $forDate = (string) ($request->request->get('forDate') ?? $request->query->get('forDate', ''));
        if (!BusinessDate::isValid($forDate)) {
            $forDate = BusinessDate::next($today);
        }

        foreach ($this->store->plan($forDate, $workstationId) as $line) {
            if ($line->productId === $productId) {
                return [$line, $forDate, $workstationId];
            }
        }

        throw $this->createNotFoundException('PLAN_LINE_NOT_FOUND');
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

        return $this->render('count/labels.html.twig', $view + [
            'printedBy' => $consultant->displayName(),
            // UNE étiquette par pièce : trente crèmes, trente pots, trente
            // étiquettes. Le compte se fait ici et non dans le gabarit —
            // l'arrondi vers le haut et le plafond de planche sont des règles,
            // pas de la mise en page.
            'sheet' => LabelSheet::of($view['lines']),
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

        // Un seul produit : c'est l'icône d'impression d'UNE ligne. La planche
        // ne porte alors que ses étiquettes, ce qui est exactement ce qu'on
        // veut quand on vient de terminer cette fournée-là et qu'on ne va pas
        // gaspiller une feuille pour les quatorze autres.
        $productId = trim((string) $request->query->get('productId', ''));

        if ($productId !== '') {
            $lines = array_values(array_filter(
                $lines,
                static fn ($line): bool => $line->productId === $productId,
            ));
        }

        // Qui a fait quoi, et l'avancement qui en découle. Lus ici plutôt que
        // dans le gabarit : l'écran et la planche d'étiquettes partagent cette
        // vue, et une lecture par ligne aurait posé une requête par produit.
        $entries = $this->store->productionEntries($forDate, $workstationId);

        $doneBy = [];
        foreach ($entries as $id => $entry) {
            $doneBy[$id] = $this->consultants->consultant($entry->consultantId)?->displayName();
        }

        return [
            'entries' => $entries,
            'doneBy' => $doneBy,
            'progress' => ProductionProgress::of($lines, $entries),
            'productId' => $productId,
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
                // Décimal, et non entier : les graduations vont par huitièmes,
                // et (int) '12.5' vaudrait 12 — soit un niveau jamais proposé,
                // ramené ensuite au plus proche. La saisie serait faussée en
                // silence à chaque bac entamé.
                $percent = is_scalar($fractions[$productId])
                    ? (float) str_replace(',', '.', (string) $fractions[$productId])
                    : 0.0;

                // Ni contenant plein ni fraction : rien n'a été compté. Un
                // contenant entamé seul, lui, est bien une saisie.
                if ($value === '' && $percent === 0.0) {
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
