<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\Consultant;
use Merisu\Inventory\Adapter\Workstation;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\PinHasher;
use Merisu\Inventory\Store\ConsultantStore;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Équipe — comptes des vendeurs et postes de travail.
 *
 * ⚠️ Cet écran pilote l'implémentation de REPLI des consultants. Si le module
 * « Consultant / Stanowisko » de l'hôte est branché à sa place (alias dans
 * services.yaml), il devient sans effet : les comptes se gèrent alors là-bas,
 * et cet écran doit être retiré de la navigation.
 *
 * Trois règles le gouvernent, et toutes trois protègent l'audit :
 *   · un code PIN identifie une personne, donc il est unique ;
 *   · une fiche qui a laissé des traces ne s'efface pas, elle se désactive ;
 *   · il reste toujours un administrateur actif, sinon plus personne n'ouvre
 *     cet écran.
 */
#[Route('/admin/equipe')]
final class AdminTeamController extends AbstractController
{
    public function __construct(
        private readonly ConsultantStore $team,
        private readonly PinHasher $hasher,
        private readonly Store $store,
        private readonly CurrentUser $currentUser,
    ) {
    }

    #[Route('', name: 'admin_team', methods: ['GET'])]
    public function index(): Response
    {
        $this->currentUser->requireAdmin();

        return $this->render('admin/team.html.twig', [
            'consultants' => $this->team->consultants(),
            'workstations' => $this->team->workstations(),
            'roles' => Role::cases(),
            'locales' => Locale::all(),
            // Boutiques déjà citées, proposées en autocomplétion : sans elles,
            // « Merisù Centrum » finirait écrit de trois façons différentes.
            'shops' => $this->boutiquesConnues(),
        ]);
    }

    // ── Consultants ─────────────────────────────────────────────────────────

    #[Route('/consultant/{id}', name: 'admin_team_save', methods: ['POST'])]
    public function save(Request $request, string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $nouveau = $id === 'nouveau';
        $existant = $nouveau ? null : $this->team->consultant($id);

        if (!$nouveau && $existant === null) {
            throw $this->createNotFoundException();
        }

        $prenom = mb_substr(trim((string) $request->request->get('firstName', '')), 0, 64);
        $nom = mb_substr(trim((string) $request->request->get('lastName', '')), 0, 64);

        if ($prenom === '' && $nom === '') {
            return $this->erreur('admin.team.errorNameRequired');
        }

        $role = Role::tryFrom((string) $request->request->get('role')) ?? Role::Consultant;
        $actif = $request->request->getBoolean('active');

        // Dernier administrateur actif : ni rétrogradé, ni désactivé. Le
        // contrôle porte sur le RÉSULTAT de l'enregistrement, pas sur la fiche
        // modifiée — c'est ce qui compte pour rester capable d'entrer.
        if (!$nouveau) {
            $resteraAdmin = $role->isAdmin() && $actif;
            if (!$resteraAdmin && $this->team->activeAdminCount(exceptId: $id) === 0) {
                return $this->erreur('admin.team.errorLastAdmin');
            }
        }

        // Le code n'est modifié que s'il est fourni : l'administrateur ne peut
        // pas le relire, et devoir le ressaisir à chaque correction de nom
        // l'obligerait à en inventer un nouveau à chaque fois.
        $pinBrut = trim((string) $request->request->get('pin', ''));
        $pinHash = null;

        if ($pinBrut !== '') {
            if (!PinHasher::isWellFormed($pinBrut)) {
                return $this->erreur('admin.team.errorPinFormat');
            }

            $pinHash = $this->hasher->hash($pinBrut);

            if ($pinHash !== null && $this->team->pinTakenBy($pinHash, $nouveau ? null : $id) !== null) {
                return $this->erreur('admin.team.errorPinTaken');
            }
        } elseif ($nouveau) {
            // Une fiche sans code ne peut pas se connecter. On l'accepte — un
            // compte se prépare parfois avant l'arrivée de la personne — mais
            // on le dit, sinon l'admin croira à une panne.
            $this->addFlash('success', 'admin.team.noticeNoPin');
        }

        $posteHabituel = trim((string) $request->request->get('defaultWorkstationId', ''));

        // Même avertissement pour le poste : `SecurityController` refuse la
        // connexion d'un vendeur sans poste habituel, avec un message que le
        // vendeur voit et que l'administrateur, lui, ne verra jamais. Sans ce
        // rappel, la fiche paraît complète et la connexion paraît cassée.
        if ($posteHabituel === '' && !$role->isAdmin() && $actif) {
            $this->addFlash('success', 'admin.team.noticeNoWorkstation');
        }

        $identifiant = $nouveau ? Store::uuid() : $id;

        $this->team->saveConsultant(
            new Consultant(
                $identifiant,
                $prenom,
                $nom,
                $role,
                $posteHabituel === '' ? null : $posteHabituel,
                $actif,
                mb_substr(trim((string) $request->request->get('email', '')), 0, 190) ?: null,
                null,
                self::listeLibre((string) $request->request->get('shops', '')),
                $request->request->all()['workstations'] ?? [],
                Locale::tryFromLoose((string) $request->request->get('locale')),
            ),
            $pinHash,
            max(0, (int) $request->request->get('sortOrder', 0)),
        );

        $this->store->audit(
            $admin->id,
            $admin->role->value,
            $nouveau ? 'CONSULTANT_CREATED' : 'CONSULTANT_UPDATED',
            null,
            null,
            // Jamais le code, ni son empreinte : l'historique est consultable
            // en administration, et n'a pas à en porter la trace.
            ['consultantId' => $identifiant, 'pinChanged' => $pinHash !== null],
        );

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_team');
    }

    #[Route('/consultant/{id}/supprimer', name: 'admin_team_delete', methods: ['POST'])]
    public function delete(string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        if ($id === $admin->id) {
            return $this->erreur('admin.team.errorSelfDelete');
        }

        if ($this->team->activeAdminCount(exceptId: $id) === 0) {
            return $this->erreur('admin.team.errorLastAdmin');
        }

        if (!$this->team->deleteConsultant($id)) {
            return $this->erreur('admin.team.errorHasHistory');
        }

        $this->store->audit($admin->id, $admin->role->value, 'CONSULTANT_DELETED', null, null, ['consultantId' => $id]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_team');
    }

    // ── Postes de travail ───────────────────────────────────────────────────

    #[Route('/poste/{id}', name: 'admin_team_workstation_save', methods: ['POST'])]
    public function saveWorkstation(Request $request, string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $nom = mb_substr(trim((string) $request->request->get('name', '')), 0, 128);

        if ($nom === '') {
            return $this->erreur('admin.team.errorWorkstationName');
        }

        $identifiant = $id === 'nouveau' ? Store::uuid() : $id;

        $this->team->saveWorkstation(
            new Workstation($identifiant, $nom, $request->request->getBoolean('active')),
            max(0, (int) $request->request->get('sortOrder', 0)),
        );

        $this->store->audit($admin->id, $admin->role->value, 'WORKSTATION_SAVED', $identifiant, null, ['name' => $nom]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_team');
    }

    #[Route('/poste/{id}/supprimer', name: 'admin_team_workstation_delete', methods: ['POST'])]
    public function deleteWorkstation(string $id): Response
    {
        $admin = $this->currentUser->requireAdmin();

        if (!$this->team->deleteWorkstation($id)) {
            return $this->erreur('admin.team.errorWorkstationUsed');
        }

        $this->store->audit($admin->id, $admin->role->value, 'WORKSTATION_DELETED', $id, null, []);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_team');
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    private function erreur(string $cle): Response
    {
        $this->addFlash('error', $cle);

        return $this->redirectToRoute('admin_team');
    }

    /**
     * Champ « boutiques » : une par ligne, ou séparées par des virgules.
     *
     * @return list<string>
     */
    private static function listeLibre(string $brut): array
    {
        $morceaux = preg_split('/[\r\n,;]+/', $brut) ?: [];

        return array_values(array_filter(array_map(trim(...), $morceaux), static fn (string $v): bool => $v !== ''));
    }

    /** @return list<string> */
    private function boutiquesConnues(): array
    {
        $vues = [];
        foreach ($this->team->consultants() as $consultant) {
            foreach ($consultant->shops as $boutique) {
                $vues[$boutique] = true;
            }
        }

        $noms = array_keys($vues);
        sort($noms, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $noms;
    }
}
