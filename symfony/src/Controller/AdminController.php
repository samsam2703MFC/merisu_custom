<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Adapter\RecipeServiceInterface;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ChecklistSection;
use Merisu\Inventory\Domain\CountMode;
use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Domain\DayNote;
use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\GeneralSettings;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\RoundingMode;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Service\ReportService;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** §7.5 à §7.9 — Écrans d'administration. Réservés au rôle ADMIN. */
#[Route('/admin')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly Store $store,
        private readonly CurrentUser $currentUser,
        private readonly ReportService $reports,
        private readonly InventoryService $inventory,
        private readonly RecipeServiceInterface $recipes,
        private readonly ConsultantServiceInterface $consultants,
    ) {
    }

    #[Route('', name: 'admin_home', methods: ['GET'])]
    public function home(): Response
    {
        $this->currentUser->requireAdmin();

        return $this->render('admin/home.html.twig');
    }

    // ── §7.6 Produits ───────────────────────────────────────────────────────

    #[Route('/produits', name: 'admin_products', methods: ['GET'])]
    public function products(): Response
    {
        $this->currentUser->requireAdmin();

        $products = $this->store->products();

        // Catégories déjà employées, proposées en autocomplétion : sans elles,
        // « Tiramisu » et « tiramisus » finiraient par cohabiter et le filtre
        // de production les traiterait comme deux catégories distinctes.
        $categories = [];
        foreach ($products as $product) {
            if ($product->category !== '') {
                $categories[$product->category] = true;
            }
        }
        $categories = array_keys($categories);
        sort($categories, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $this->render('admin/products.html.twig', [
            'products' => $products,
            'categories' => $categories,
            'roundingModes' => RoundingMode::cases(),
            'countModes' => CountMode::all(),
        ]);
    }

    #[Route('/produits/{id}', name: 'admin_product_save', methods: ['POST'])]
    public function saveProduct(Request $request, string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $product = $this->store->product($id);
        if ($product === null) {
            throw $this->createNotFoundException('PRODUCT_NOT_FOUND');
        }

        // Un libellé par langue : ce sont des DONNÉES, pas des chaînes d'interface.
        $names = [];
        foreach (Locale::all() as $locale) {
            $value = trim((string) $request->request->get('name_' . $locale->value, ''));
            if ($value !== '') {
                $names[$locale->value] = mb_substr($value, 0, 120);
            }
        }

        $wasteFactor = max(0.0, (float) str_replace(',', '.', (string) $request->request->get('wasteFactor', '0')));
        $roundingStep = (float) str_replace(',', '.', (string) $request->request->get('roundingStep', '1'));

        $this->store->saveProduct($product->with(
            name: $names !== [] ? $names : $product->name,
            unit: mb_substr(trim((string) $request->request->get('unit', $product->unit)), 0, 16) ?: $product->unit,
            active: $request->request->getBoolean('active'),
            wasteFactor: $wasteFactor,
            roundingStep: $roundingStep > 0 ? $roundingStep : $product->roundingStep,
            roundingMode: RoundingMode::tryFrom((string) $request->request->get('roundingMode')) ?? $product->roundingMode,
            recipeRef: trim((string) $request->request->get('recipeRef', '')) ?: null,
            countMode: CountMode::tryFromLoose($request->request->get('countMode')) ?? $product->countMode,
            // Catégorie de production, en texte libre : c'est l'atelier qui
            // décide de son vocabulaire (Tiramisu, Boissons, Verrines…), et il
            // peut le changer sans redéploiement (§2). Vide = non classé.
            category: mb_substr(trim((string) $request->request->get('category', '')), 0, 64),
            // Mentions de l'étiquette. La durée de vie à 0 signifie « non
            // renseignée » : l'étiquette n'imprime alors aucune DLC, plutôt
            // qu'une date fausse qui engagerait la boutique.
            shelfLifeDays: max(0, (int) $request->request->get('shelfLifeDays', 0)),
            ingredients: $this->parLangue($request, 'ingredients'),
            allergens: $this->parLangue($request, 'allergens'),
        ));

        $this->store->audit($admin->id, $admin->role->value, 'PRODUCT_UPDATED', null, null, ['productId' => $id]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_products');
    }

    /**
     * Champ multilingue d'un formulaire produit : `ingredients_fr`, `_pl`…
     *
     * @return array<string,string>
     */
    private function parLangue(Request $request, string $champ): array
    {
        $valeurs = [];

        foreach (Locale::all() as $locale) {
            $valeur = trim((string) $request->request->get($champ . '_' . $locale->value, ''));
            if ($valeur !== '') {
                $valeurs[$locale->value] = mb_substr($valeur, 0, 600);
            }
        }

        return $valeurs;
    }

    // ── Note du jour ────────────────────────────────────────────────────────

    #[Route('/note-du-jour', name: 'admin_day_note', methods: ['GET'])]
    public function dayNote(): Response
    {
        $this->currentUser->requireAdmin();

        return $this->render('admin/day_note.html.twig', [
            'notes' => $this->store->dayNotes(),
            // Un identifiant neuf pour la ligne d'ajout : le tirer au sort dans
            // le gabarit risquerait d'écraser une consigne à chaque affichage.
            'newId' => 'note-' . Store::uuid(),
            'locales' => Locale::all(),
        ]);
    }

    /**
     * Enregistre la note du jour entière : intertitres, textes, ordre, activation.
     *
     * Un seul envoi pour tout l'écran, comme la check-list : rédiger consigne
     * par consigne ferait autant d'allers-retours que de consignes.
     */
    #[Route('/note-du-jour', name: 'admin_day_note_save', methods: ['POST'])]
    public function saveDayNote(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        /** @var array<string,array<string,mixed>> $lignes */
        $lignes = $request->request->all('note');
        $enregistres = 0;
        $supprimes = 0;

        foreach ($lignes as $id => $champs) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }

            $heading = [];
            $body = [];

            foreach (Locale::all() as $locale) {
                $titre = trim((string) ($champs['heading_' . $locale->value] ?? ''));
                if ($titre !== '') {
                    $heading[$locale->value] = mb_substr($titre, 0, 120);
                }

                // Les retours à la ligne sont conservés : « Ciao à l'entrée »
                // et « Grazie au départ » sont deux gestes, et les écrire l'un
                // sous l'autre les rend plus lisibles qu'un paragraphe.
                $texte = trim((string) ($champs['body_' . $locale->value] ?? ''));
                if ($texte !== '') {
                    $body[$locale->value] = mb_substr($texte, 0, 1000);
                }
            }

            // Tout vide = suppression. C'est le geste naturel pour retirer une
            // consigne, et il évite un bouton « Supprimer » par ligne.
            if ($heading === [] && $body === []) {
                if ($this->store->dayNote($id) !== null) {
                    $this->store->deleteDayNote($id);
                    ++$supprimes;
                }

                continue;
            }

            $this->store->saveDayNote(new DayNote(
                $id,
                $heading,
                $body,
                (int) ($champs['sortOrder'] ?? 0),
                (bool) ($champs['active'] ?? false),
            ));
            ++$enregistres;
        }

        $this->store->audit($admin->id, $admin->role->value, 'DAY_NOTE_UPDATE', null, null, [
            'saved' => $enregistres,
            'deleted' => $supprimes,
        ]);

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_day_note');
    }

    // ── Check-list ──────────────────────────────────────────────────────────

    #[Route('/check-list', name: 'admin_checklist', methods: ['GET'])]
    public function checklist(): Response
    {
        $this->currentUser->requireAdmin();

        $parSection = [];
        foreach (ChecklistSection::all() as $section) {
            $parSection[$section->value] = [];
        }

        foreach ($this->store->checklistItems() as $item) {
            $parSection[$item->section->value][] = $item;
        }

        // Un identifiant neuf par volet pour la ligne d'ajout : le réutiliser
        // ou le tirer au sort dans le gabarit risquerait d'écraser un point.
        $nouveaux = [];
        foreach (ChecklistSection::all() as $section) {
            $nouveaux[$section->value] = strtolower($section->value) . '-' . Store::uuid();
        }

        return $this->render('admin/checklist.html.twig', [
            'sections' => ChecklistSection::all(),
            'itemsBySection' => $parSection,
            'newIds' => $nouveaux,
            'locales' => Locale::all(),
        ]);
    }

    /**
     * Enregistre la check-list entière : libellés, ordre, activation.
     *
     * Un seul envoi pour tout l'écran, comme la matrice des seuils : régler
     * point par point ferait autant d'allers-retours que de contrôles.
     */
    #[Route('/check-list', name: 'admin_checklist_save', methods: ['POST'])]
    public function saveChecklist(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        /** @var array<string,array<string,mixed>> $lignes */
        $lignes = $request->request->all('item');
        $enregistres = 0;
        $supprimes = 0;

        foreach ($lignes as $id => $champs) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }

            $labels = [];
            foreach (Locale::all() as $locale) {
                $valeur = trim((string) ($champs['label_' . $locale->value] ?? ''));
                if ($valeur !== '') {
                    $labels[$locale->value] = mb_substr($valeur, 0, 200);
                }
            }

            // Tous les libellés vides = suppression. C'est le geste naturel
            // pour retirer un point, et il évite un bouton « Supprimer » par
            // ligne sur un écran qui en compte déjà beaucoup.
            if ($labels === []) {
                if ($this->store->checklistItem($id) !== null) {
                    $this->store->deleteChecklistItem($id);
                    ++$supprimes;
                }

                continue;
            }

            $section = ChecklistSection::tryFromLoose((string) ($champs['section'] ?? ''))
                ?? ChecklistSection::Opening;

            $this->store->saveChecklistItem(new ChecklistItem(
                $id,
                $section,
                $labels,
                (int) ($champs['sortOrder'] ?? 0),
                (bool) ($champs['active'] ?? false),
                (bool) ($champs['required'] ?? false),
            ));
            ++$enregistres;
        }

        $this->store->audit($admin->id, $admin->role->value, 'CHECKLIST_ITEMS_UPDATE', null, null, [
            'saved' => $enregistres,
            'deleted' => $supprimes,
        ]);

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_checklist');
    }

    // ── §7.5 Matrice des seuils ─────────────────────────────────────────────

    #[Route('/seuils', name: 'admin_par_matrix', methods: ['GET'])]
    public function parMatrix(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        // Seuls les seuils GLOBAUX sont édités ici (question ouverte §10) ; le
        // domaine gère déjà les seuils par poste, qui priment quand ils existent.
        $values = [];
        foreach ($this->store->parMatrix() as $entry) {
            if ($entry->workstationId === null) {
                $values[$entry->productId][$entry->dayOfWeek->value] = $entry->requiredPieces;
            }
        }

        return $this->render('admin/par_matrix.html.twig', [
            'products' => $this->store->products(),
            'days' => DayOfWeek::all(),
            'values' => $values,
            'productsInRows' => $request->query->get('orientation') !== 'days',
        ]);
    }

    #[Route('/seuils', name: 'admin_par_matrix_save', methods: ['POST'])]
    public function saveParMatrix(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        /** @var array<string, array<string, mixed>> $raw */
        $raw = $request->request->all('par');
        $saved = 0;

        foreach ($raw as $productId => $byDay) {
            if ($this->store->product((string) $productId) === null) {
                continue;
            }

            foreach ($byDay as $dayValue => $value) {
                $day = DayOfWeek::tryFrom((string) $dayValue);
                if ($day === null) {
                    continue;
                }

                $value = is_scalar($value) ? trim((string) $value) : '';
                // Case vidée ⇒ suppression du seuil (≠ seuil fixé à 0).
                $required = $value === '' ? null : (float) str_replace(',', '.', $value);

                if ($required !== null && $required < 0) {
                    continue;
                }

                $this->store->saveParEntry((string) $productId, $day, $required);
                ++$saved;
            }
        }

        $this->store->audit($admin->id, $admin->role->value, 'PAR_MATRIX_UPDATED', null, null, ['cells' => $saved]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_par_matrix');
    }

    // ── §7.7 Paramètres généraux ────────────────────────────────────────────

    #[Route('/parametres', name: 'admin_settings', methods: ['GET'])]
    public function settings(): Response
    {
        $this->currentUser->requireAdmin();

        return $this->render('admin/settings.html.twig', [
            'settings' => $this->store->settings(),
            'locales' => Locale::all(),
        ]);
    }

    #[Route('/parametres', name: 'admin_settings_save', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();
        $current = $this->store->settings();

        $time = static fn (string $key, string $fallback): string => preg_match(
            '/^([01]\d|2[0-3]):[0-5]\d$/',
            (string) $request->request->get($key, ''),
        ) === 1 ? (string) $request->request->get($key) : $fallback;

        // Un fuseau inconnu est rejeté ici : accepté, il fausserait silencieusement
        // toutes les dates métier.
        $timezone = trim((string) $request->request->get('timezone', ''));
        if ($timezone === '' || !\in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $timezone = $current->timezone;
        }

        $tolerance = (float) str_replace(',', '.', (string) $request->request->get('deltaTolerance', '0.05'));

        $this->store->saveSettings(new GeneralSettings(
            $time('openingTime', $current->openingTime),
            $time('closingTime', $current->closingTime),
            $timezone,
            Locale::tryFromLoose((string) $request->request->get('defaultLocale')) ?? $current->defaultLocale,
            // Les photos ayant quitté les écrans de comptage, ces deux
            // réglages ne sont plus proposés : on les remet à faux plutôt que
            // de laisser traîner une valeur que plus rien n'honore.
            false,
            false,
            $tolerance >= 0 ? $tolerance : $current->deltaTolerance,
            // Objectif de la jauge tiramisu. Négatif ramené à zéro, ce qui
            // signifie « pas d'objectif » et fait simplement disparaître la
            // jauge — jamais une barre qui se remplirait à l'envers.
            max(0, (int) $request->request->get('monthlyTiramisuTarget', 0)),
        ));

        $this->store->audit($admin->id, $admin->role->value, 'SETTINGS_UPDATED');
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_settings');
    }

    // ── §7.8 Delta technique ────────────────────────────────────────────────

    #[Route('/delta', name: 'admin_delta', methods: ['GET'])]
    public function delta(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        [$from, $to] = $this->parsePeriod($request);

        $materials = [];
        foreach ($this->recipes->materials() as $material) {
            $materials[$material->id] = $material;
        }

        return $this->render('admin/delta.html.twig', [
            'from' => $from,
            'to' => $to,
            'report' => $this->reports->delta($from, $to),
            'matrix' => $this->reports->matrix($from, $to),
            'materials' => $materials,
        ]);
    }

    #[Route('/delta/consommation', name: 'admin_material_movement', methods: ['POST'])]
    public function addMovement(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $date = (string) $request->request->get('date', '');
        $materialId = (string) $request->request->get('materialId', '');
        $qty = str_replace(',', '.', (string) $request->request->get('realQty', ''));

        if (BusinessDate::isValid($date) && $materialId !== '' && is_numeric($qty)) {
            $this->store->addMaterialMovement(
                $date,
                $materialId,
                (float) $qty,
                $admin->id,
                null,
                trim((string) $request->request->get('note', '')) ?: null,
            );
            $this->addFlash('success', 'common.saved');
        } else {
            $this->addFlash('error', 'errors.INVALID_QTY');
        }

        return $this->redirectToRoute('admin_delta', [
            'from' => $request->request->get('from'),
            'to' => $request->request->get('to'),
        ]);
    }

    /** Exports CSV (§6). */
    #[Route('/delta/export.csv', name: 'admin_delta_csv', methods: ['GET'])]
    public function deltaCsv(Request $request): Response
    {
        $this->currentUser->requireAdmin();
        [$from, $to] = $this->parsePeriod($request);
        $locale = Locale::tryFromLoose($request->getLocale()) ?? Locale::Fr;

        return $this->csvResponse(
            $this->reports->deltaCsv($from, $to, $locale),
            sprintf('merisu-delta-%s_%s.csv', $from, $to),
        );
    }

    #[Route('/matrice/export.csv', name: 'admin_matrix_csv', methods: ['GET'])]
    public function matrixCsv(Request $request): Response
    {
        $this->currentUser->requireAdmin();
        [$from, $to] = $this->parsePeriod($request);
        $locale = Locale::tryFromLoose($request->getLocale()) ?? Locale::Fr;

        return $this->csvResponse(
            $this->reports->matrixCsv($from, $to, $locale),
            sprintf('merisu-matrice-%s_%s.csv', $from, $to),
        );
    }

    // ── §7.9 Historique / audit ─────────────────────────────────────────────

    #[Route('/historique', name: 'admin_audit', methods: ['GET'])]
    public function audit(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        [$from, $to] = $this->parsePeriod($request, 13);

        return $this->render('admin/audit.html.twig', [
            'from' => $from,
            'to' => $to,
            'entries' => $this->store->auditEntries($from, $to),
            'workstations' => $this->consultants->workstations(),
            'today' => $this->inventory->today(),
        ]);
    }

    /** Déverrouillage d'une saisie validée — ADMIN uniquement, tracé (§5.3). */
    #[Route('/historique/deverrouiller', name: 'admin_unlock', methods: ['POST'])]
    public function unlock(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $date = (string) $request->request->get('date', '');
        $moment = CountMoment::tryFrom((string) $request->request->get('moment'));
        $workstationId = (string) $request->request->get('workstationId', '');

        if (!BusinessDate::isValid($date) || $moment === null || $workstationId === '') {
            $this->addFlash('error', 'errors.INVALID_DATE');

            return $this->redirectToRoute('admin_audit');
        }

        $affected = $this->inventory->unlock(
            $date,
            $workstationId,
            $moment,
            $admin->id,
            $admin->role,
            trim((string) $request->request->get('reason', '')) ?: null,
        );

        $this->addFlash($affected > 0 ? 'success' : 'error', $affected > 0 ? 'admin.audit.unlockDone' : 'errors.COUNT_NOT_FOUND');

        return $this->redirectToRoute('admin_audit');
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    /** @return array{string, string} */
    private function parsePeriod(Request $request, int $defaultSpan = 6): array
    {
        $today = $this->inventory->today();

        $to = (string) $request->query->get('to', $today);
        if (!BusinessDate::isValid($to)) {
            $to = $today;
        }

        $from = (string) $request->query->get('from', BusinessDate::addDays($to, -$defaultSpan));
        if (!BusinessDate::isValid($from) || $from > $to) {
            $from = BusinessDate::addDays($to, -$defaultSpan);
        }

        return [$from, $to];
    }

    private function csvResponse(string $csv, string $fileName): Response
    {
        // BOM UTF-8 : sans lui, Excel affiche mal les accents des libellés FR/PL/IT/ES.
        $response = new Response("\u{FEFF}" . $csv);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $fileName));

        return $response;
    }
}
