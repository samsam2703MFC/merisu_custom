<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fiche profil du consultant connecté.
 *
 * Toutes les informations affichées appartiennent au module Consultant
 * existant : cet écran les présente, il ne les modifie pas. Une éventuelle
 * modification devra se faire dans le module d'origine, sans quoi les deux
 * référentiels divergeraient.
 */
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly ConsultantServiceInterface $consultants,
    ) {
    }

    #[Route('/profil', name: 'profile', methods: ['GET'])]
    public function show(): Response
    {
        $consultant = $this->currentUser->requireConsultant();

        // Postes lisibles : le module existant fournit des identifiants, la
        // fiche doit afficher des noms.
        $names = [];
        foreach ($this->consultants->workstations() as $workstation) {
            $names[$workstation->id] = $workstation->name;
        }

        $assigned = $consultant->workstations !== []
            ? $consultant->workstations
            : array_filter([$consultant->defaultWorkstationId]);

        return $this->render('security/profile.html.twig', [
            'consultant' => $consultant,
            'currentWorkstation' => $names[$this->currentUser->workstationId()] ?? $this->currentUser->workstationId(),
            'workstationNames' => array_values(array_map(
                static fn (string $id): string => $names[$id] ?? $id,
                $assigned,
            )),
            'workstations' => $this->consultants->workstations(),
            'locales' => Locale::all(),
        ]);
    }
}
