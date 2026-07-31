<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Security\LocaleSubscriber;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** §7.1 — Connexion, choix du poste et sélecteur de langue. */
final class SecurityController extends AbstractController
{
    public function __construct(
        private readonly ConsultantServiceInterface $consultants,
        private readonly CurrentUser $currentUser,
        private readonly Store $store,
    ) {
    }

    #[Route('/connexion', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($this->currentUser->isLoggedIn()) {
            return $this->redirectToRoute('count_morning');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $login = trim((string) $request->request->get('login'));
            $secret = (string) $request->request->get('secret');
            $workstationId = trim((string) $request->request->get('workstationId'));

            $consultant = $this->consultants->authenticate($login, $secret);

            if ($consultant === null) {
                $error = 'INVALID_CREDENTIALS';
            } elseif ($workstationId !== '' && $this->consultants->workstation($workstationId) === null) {
                $error = 'UNKNOWN_WORKSTATION';
            } else {
                $this->currentUser->login($consultant, $workstationId !== '' ? $workstationId : null);
                $this->store->audit($consultant->id, $consultant->role->value, 'LOGIN', $this->currentUser->workstationId());

                return $this->redirectToRoute('count_morning');
            }
        }

        return $this->render('security/login.html.twig', [
            'workstations' => $this->consultants->workstations(),
            'error' => $error,
        ]);
    }

    #[Route('/deconnexion', name: 'logout', methods: ['POST'])]
    public function logout(): Response
    {
        $this->currentUser->logout();

        return $this->redirectToRoute('login');
    }

    /** Changement de poste en cours de journée (remplacement, renfort). */
    #[Route('/poste', name: 'select_workstation', methods: ['POST'])]
    public function selectWorkstation(Request $request): Response
    {
        $this->currentUser->requireConsultant();

        $workstationId = (string) $request->request->get('workstationId');

        if ($this->consultants->workstation($workstationId) !== null) {
            $this->currentUser->selectWorkstation($workstationId);
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('count_morning'));
    }

    /** Changement de langue : mémorisé, il prime sur le paramètre d'administration. */
    #[Route('/langue', name: 'select_locale', methods: ['POST'])]
    public function selectLocale(Request $request): Response
    {
        $locale = Locale::tryFromLoose((string) $request->request->get('locale'));

        if ($locale !== null) {
            $request->getSession()->set(LocaleSubscriber::SESSION_KEY, $locale->value);
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('count_morning'));
    }
}
