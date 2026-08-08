<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Domain\Competency;
use Merisu\Inventory\Domain\JobPosition;
use Merisu\Inventory\Domain\PositionLevel;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Store\HrStore;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Postes & compétences — les fonctions RH, leurs niveaux, les acquis.
 *
 * ⚠️ CE N'EST PAS L'ÉCRAN DES POSTES DE TRAVAIL.
 *
 * Un poste de travail (« stanowisko », ws-1, ws-2) est l'endroit où l'on
 * compte : il se règle dans Admin ▸ Équipe, et il décide de ce qu'on voit à
 * l'écran de saisie. Un poste RH est la fonction qu'on occupe — vendeur, chef
 * de rang — et il ne décide de rien dans l'inventaire.
 *
 * Les deux notions sont tenues séparées jusque dans le vocabulaire des écrans,
 * à la demande expresse de la boutique. Les confondre aurait fait dépendre le
 * plan de production d'une promotion.
 *
 * ── Ce que l'hôte attend
 *
 * Une affectation est un COUPLE `position_id` + `level_id`, une compétence un
 * simple `competency_id` (voir `PUT /employees/{id}/positions` et
 * `/competencies`). Les écrans produisent exactement cette forme ; le jour du
 * branchement, seul l'adaptateur change.
 */
#[Route('/admin/postes')]
final class AdminPositionController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly HrStore $hr,
        private readonly Store $store,
        private readonly ConsultantServiceInterface $consultants,
    ) {
    }

    #[Route('', name: 'admin_positions', methods: ['GET'])]
    public function index(): Response
    {
        $this->currentUser->requireAdmin();

        $competences = $this->hr->competencies();

        return $this->render('admin/positions.html.twig', [
            'positions' => $this->hr->positions(),
            'competencies' => $competences,
            // Groupées ICI et non dans le gabarit : Twig ne conserve pas une
            // variable d'une itération à l'autre, et les intertitres se
            // répétaient à chaque ligne.
            'grouped' => Competency::group($competences),
            'consultants' => $this->consultants->consultants(),
            'assignedPositions' => $this->hr->employeePositions(),
            'assignedCompetencies' => $this->hr->employeeCompetencies(),
            'newPositionId' => 'pos-' . Store::uuid(),
            'newCompetencyId' => 'cmp-' . Store::uuid(),
        ]);
    }

    // ── Postes ──────────────────────────────────────────────────────────────

    #[Route('/poste/{id}', name: 'admin_position_save', methods: ['POST'])]
    public function savePosition(Request $request, string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $nom = mb_substr(trim((string) $request->request->get('name', '')), 0, 120);

        if ($nom === '') {
            $this->addFlash('error', 'admin.positions.errorNameEmpty');

            return $this->redirectToRoute('admin_positions');
        }

        $this->hr->savePosition(new JobPosition(
            $id,
            $nom,
            mb_substr(trim((string) $request->request->get('description', '')), 0, 500) ?: null,
            max(0, (int) $request->request->get('sortOrder', 0)),
        ));

        $this->store->audit($admin->id, $admin->role->value, 'JOB_POSITION_SAVED', null, null, ['positionId' => $id]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_positions');
    }

    #[Route('/poste/{id}/supprimer', name: 'admin_position_delete', methods: ['POST'])]
    public function deletePosition(string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $this->hr->deletePosition($id);

        $this->store->audit($admin->id, $admin->role->value, 'JOB_POSITION_DELETED', null, null, ['positionId' => $id]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_positions');
    }

    // ── Niveaux ─────────────────────────────────────────────────────────────

    #[Route('/poste/{positionId}/niveau', name: 'admin_position_level_add', methods: ['POST'])]
    public function addLevel(Request $request, string $positionId): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $nom = mb_substr(trim((string) $request->request->get('name', '')), 0, 120);

        if ($nom === '') {
            $this->addFlash('error', 'admin.positions.errorNameEmpty');

            return $this->redirectToRoute('admin_positions');
        }

        $this->hr->saveLevel(new PositionLevel(
            'lvl-' . Store::uuid(),
            $positionId,
            $nom,
            mb_substr(trim((string) $request->request->get('description', '')), 0, 500) ?: null,
            max(0, (int) $request->request->get('order', 0)),
        ));

        $this->store->audit($admin->id, $admin->role->value, 'POSITION_LEVEL_SAVED', null, null, [
            'positionId' => $positionId,
        ]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_positions');
    }

    #[Route('/niveau/{id}/supprimer', name: 'admin_position_level_delete', methods: ['POST'])]
    public function deleteLevel(string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $this->hr->deleteLevel($id);

        $this->store->audit($admin->id, $admin->role->value, 'POSITION_LEVEL_DELETED', null, null, ['levelId' => $id]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_positions');
    }

    // ── Compétences ─────────────────────────────────────────────────────────

    #[Route('/competence/{id}', name: 'admin_competency_save', methods: ['POST'])]
    public function saveCompetency(Request $request, string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $nom = mb_substr(trim((string) $request->request->get('name', '')), 0, 190);

        if ($nom === '') {
            $this->addFlash('error', 'admin.positions.errorNameEmpty');

            return $this->redirectToRoute('admin_positions');
        }

        $this->hr->saveCompetency(new Competency(
            $id,
            $nom,
            mb_substr(trim((string) $request->request->get('category', '')), 0, 120),
            mb_substr(trim((string) $request->request->get('subcategory', '')), 0, 120),
            mb_substr(trim((string) $request->request->get('verificationMethod', '')), 0, 500) ?: null,
            max(0, (int) $request->request->get('sortOrder', 0)),
        ));

        $this->store->audit($admin->id, $admin->role->value, 'COMPETENCY_SAVED', null, null, ['competencyId' => $id]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_positions');
    }

    #[Route('/competence/{id}/supprimer', name: 'admin_competency_delete', methods: ['POST'])]
    public function deleteCompetency(string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $this->hr->deleteCompetency($id);

        $this->store->audit($admin->id, $admin->role->value, 'COMPETENCY_DELETED', null, null, [
            'competencyId' => $id,
        ]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_positions');
    }

    // ── Affectation d'une personne ──────────────────────────────────────────

    /**
     * Le poste, son niveau et les compétences d'une personne, d'un seul envoi.
     *
     * Le poste et le niveau voyagent ENSEMBLE dans un même champ (`pos|lvl`) :
     * deux listes déroulantes séparées auraient laissé choisir le niveau d'un
     * autre poste, et l'hôte aurait reçu un couple qui n'existe pas.
     */
    #[Route('/affectation/{consultantId}', name: 'admin_position_assign', methods: ['POST'])]
    public function assign(Request $request, string $consultantId): Response
    {
        $admin = $this->currentUser->requireAdmin();

        if ($this->consultants->consultant($consultantId) === null) {
            throw $this->createNotFoundException('CONSULTANT_NOT_FOUND');
        }

        [$posteId, $niveauId] = self::coupleDemande((string) $request->request->get('position', ''));

        // Le couple est revérifié CONTRE la liste : une requête forgée peut
        // proposer n'importe quel identifiant, et une affectation vers un
        // niveau d'un autre poste ne se verrait nulle part à l'écran.
        if ($posteId !== null && $this->couplePossible($posteId, $niveauId) === false) {
            $this->addFlash('error', 'admin.positions.errorLevelMismatch');

            return $this->redirectToRoute('admin_positions');
        }

        $this->hr->assignPosition($consultantId, $posteId, $niveauId);

        /** @var list<string> $competences */
        $competences = array_values(array_filter(
            (array) ($request->request->all()['competencies'] ?? []),
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));

        $this->hr->assignCompetencies($consultantId, $competences);

        $this->store->audit($admin->id, $admin->role->value, 'EMPLOYEE_POSITION_ASSIGNED', null, null, [
            'consultantId' => $consultantId,
            'positionId' => $posteId,
            'levelId' => $niveauId,
            'competencies' => count($competences),
        ]);

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_positions');
    }

    /** @return array{?string, ?string} */
    private static function coupleDemande(string $brut): array
    {
        $morceaux = explode('|', trim($brut), 2);

        if (count($morceaux) !== 2 || $morceaux[0] === '' || $morceaux[1] === '') {
            return [null, null];
        }

        return [$morceaux[0], $morceaux[1]];
    }

    private function couplePossible(string $positionId, ?string $levelId): bool
    {
        if ($levelId === null) {
            return false;
        }

        foreach ($this->hr->positions() as $poste) {
            if ($poste->id === $positionId) {
                return $poste->level($levelId) !== null;
            }
        }

        return false;
    }
}
