<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Procedure;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\PhotoStorage;
use Merisu\Inventory\Store\ProcedureStore;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Manuel opératoire — un problème, ce qu'on fait, des photos.
 *
 * ── Ce que le manuel n'est pas
 *
 * Ce n'est pas la check-list. Celle-ci énumère ce qu'on fait CHAQUE JOUR, et
 * se coche. Le manuel décrit ce qu'on fait quand quelque chose CLOCHE, et se
 * consulte. Les mélanger aurait donné une check-list de trente points dont
 * vingt-cinq ne servent qu'une fois par trimestre.
 *
 * ── Rien n'est écrit dans le code
 *
 * Ni les procédures, ni les rayons qui les regroupent. Les pannes d'une
 * boutique ne sont pas celles de la voisine, et les figer aurait imposé un
 * déploiement pour ajouter une ligne — autant dire qu'on n'en aurait jamais
 * ajouté.
 *
 * ── Les photos font le travail que le texte ne fait pas
 *
 * « Le bac est monté trop haut » ne se décrit pas, il se montre. Elles passent
 * par le même stockage que les autres images du module, qui lit le type dans
 * les octets plutôt que dans le nom du fichier.
 */
#[Route('/admin/manuel')]
final class AdminProcedureController extends AbstractController
{
    public function __construct(
        private readonly ProcedureStore $procedures,
        private readonly Store $store,
        private readonly CurrentUser $currentUser,
        private readonly PhotoStorage $photos,
    ) {
    }

    #[Route('', name: 'admin_procedures', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        $slot = $this->procedures->nextSlot();

        return $this->render('admin/procedures.html.twig', [
            'procedures' => $this->procedures->all(),
            'blank' => new Procedure($slot['id'], [], [], [], [], '', $slot['sortOrder']),
            'topics' => $this->procedures->topics(),
            'locales' => $this->shownLocales(),
            // L'ÉNUMÉRATION, pas la chaîne : `app.request.locale` rend « fr »,
            // et les accesseurs de Procedure attendent un Locale.
            'locale' => Locale::tryFromLoose($request->getLocale()) ?? Locale::Fr,
            'open' => (string) $request->query->get('ouvrir', ''),
        ]);
    }

    #[Route('/nouvelle', name: 'admin_procedure_create', methods: ['POST'], priority: 10)]
    public function create(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $slot = $this->procedures->nextSlot();
        $procedure = $this->read($request, new Procedure($slot['id'], [], [], [], [], '', $slot['sortOrder']));

        // Sans titre dans AUCUNE langue, la procédure n'est identifiable par
        // personne : elle apparaîtrait dans la liste sous son identifiant.
        if ($procedure->title === []) {
            $this->addFlash('error', 'admin.procedures.titleRequired');

            return $this->redirectToRoute('admin_procedures');
        }

        $this->procedures->save($procedure);
        $this->store->audit($admin->id, $admin->role->value, 'PROCEDURE_CREATED', null, null, [
            'procedureId' => $procedure->id,
        ]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_procedures', ['ouvrir' => $procedure->id]);
    }

    #[Route('/{id}', name: 'admin_procedure_save', methods: ['POST'])]
    public function save(Request $request, string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $existante = $this->procedures->find($id);
        if ($existante === null) {
            throw $this->createNotFoundException('PROCEDURE_NOT_FOUND');
        }

        $procedure = $this->read($request, $existante);

        $this->procedures->save($procedure);
        $this->store->audit($admin->id, $admin->role->value, 'PROCEDURE_UPDATED', null, null, [
            'procedureId' => $id,
            'photos' => \count($procedure->photos),
        ]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_procedures', ['ouvrir' => $id]);
    }

    #[Route('/{id}/supprimer', name: 'admin_procedure_delete', methods: ['POST'])]
    public function delete(string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $procedure = $this->procedures->find($id);
        if ($procedure === null) {
            throw $this->createNotFoundException('PROCEDURE_NOT_FOUND');
        }

        $this->procedures->delete($id);
        $this->store->audit($admin->id, $admin->role->value, 'PROCEDURE_DELETED', null, null, [
            'procedureId' => $id,
            'title' => $procedure->titleText(Locale::Fr),
        ]);
        $this->addFlash('success', 'admin.procedures.deleted');

        return $this->redirectToRoute('admin_procedures');
    }

    /**
     * Lit le formulaire par-dessus une procédure existante.
     *
     * Les photos sont CUMULATIVES : celles qui sont là restent, celles qu'on
     * dépose s'ajoutent, et seules celles qu'on coche partent. Remplacer la
     * liste à chaque enregistrement aurait effacé trois photos parce qu'on
     * corrigeait une faute de frappe dans le titre.
     */
    private function read(Request $request, Procedure $base): Procedure
    {
        $textes = [];

        foreach (['title', 'problem', 'solution'] as $champ) {
            $valeurs = [];

            foreach (Locale::all() as $locale) {
                $saisi = trim((string) $request->request->get($champ . '_' . $locale->value, ''));

                if ($saisi !== '') {
                    $valeurs[$locale->value] = mb_substr($saisi, 0, 4000);
                }
            }

            $textes[$champ] = $valeurs;
        }

        // ── Les photos ──────────────────────────────────────────────────────
        $retirees = array_map(strval(...), (array) $request->request->all('removePhoto'));
        $photos = array_values(array_filter(
            $base->photos,
            static fn (string $chemin): bool => !\in_array($chemin, $retirees, true),
        ));

        foreach ((array) $request->files->get('photos') as $fichier) {
            if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
                continue;
            }

            try {
                $photos[] = $this->photos->store($fichier);
            } catch (\RuntimeException) {
                // Un fichier refusé n'interrompt pas l'enregistrement : le
                // texte de la procédure est valable, et perdre une correction
                // parce qu'un PDF a été déposé par mégarde serait une punition
                // sans rapport. L'écran montrera qu'il en manque une.
                $this->addFlash('error', 'admin.procedures.photoRejected');
            }
        }

        return $base->with(
            title: $textes['title'],
            problem: $textes['problem'],
            solution: $textes['solution'],
            photos: $photos,
            topic: mb_substr(trim((string) $request->request->get('topic', '')), 0, 64),
            sortOrder: max(0, (int) $request->request->get('sortOrder', $base->sortOrder)),
            active: $request->request->getBoolean('active'),
        );
    }

    /** @return list<Locale> */
    private function shownLocales(): array
    {
        return Locale::all();
    }
}
