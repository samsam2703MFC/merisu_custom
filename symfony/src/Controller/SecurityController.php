<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Security\PinField;
use Merisu\Inventory\Security\LocaleSubscriber;
use Merisu\Inventory\Store\ShopStore;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/** §7.1 — Connexion, choix du poste et sélecteur de langue. */
final class SecurityController extends AbstractController
{
    /**
     * La boutique de la veille, retenue sur la TABLETTE.
     *
     * Pas en session : la session meurt à la déconnexion, et le choix serait à
     * refaire deux fois par jour sur un appareil qui n'a jamais bougé.
     */
    private const SHOP_COOKIE = 'merisu_shop';

    public function __construct(
        private readonly ConsultantServiceInterface $consultants,
        private readonly CurrentUser $currentUser,
        private readonly Store $store,
        private readonly ShopStore $shops,
        #[Autowire(service: 'limiter.login_ip')]
        private readonly RateLimiterFactory $loginIpLimiter,
        #[Autowire(service: 'limiter.login_global')]
        private readonly RateLimiterFactory $loginGlobalLimiter,
    ) {
    }

    /**
     * Connexion par code PIN à 6 chiffres, sans identifiant ni choix de poste :
     * c'est le geste réel au poste de travail.
     *
     * Le poste vient de la fiche du consultant, tenue en administration. Le
     * faire choisir au vendeur, c'était lui laisser se tromper de poste et
     * fausser des comptages qu'aucun écran ne rattraperait — l'erreur ne se
     * verrait qu'au plan de production du lendemain.
     *
     * Le code étant le SEUL facteur, les tentatives sont limitées par IP et
     * globalement — 10^6 combinaisons se parcourent vite sans cela.
     */
    #[Route('/connexion', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($this->currentUser->isLoggedIn()) {
            return $this->redirectToRoute('home');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $secret = PinField::read($request);

            $byIp = $this->loginIpLimiter->create($request->getClientIp() ?? 'unknown');
            $global = $this->loginGlobalLimiter->create('login');

            if (!$byIp->consume()->isAccepted() || !$global->consume()->isAccepted()) {
                // Journalisé : une salve de tentatives doit être visible en audit.
                $this->store->audit('anonyme', 'ANONYMOUS', 'LOGIN_THROTTLED', null, null, [
                    'ip' => $request->getClientIp(),
                ]);

                return $this->render('security/login.html.twig', [
                    'error' => 'TOO_MANY_ATTEMPTS',
                ], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }

            $consultant = $this->consultants->authenticateByPin($secret);

            if ($consultant === null) {
                $error = 'INVALID_CREDENTIALS';
            } elseif ($consultant->defaultWorkstationId === null) {
                // Refusé ici, avec un message clair, plutôt que de laisser la
                // session s'ouvrir sur un écran de saisie en erreur : le
                // vendeur n'y pourrait rien, c'est à l'administrateur d'agir.
                $this->store->audit($consultant->id, $consultant->role->value, 'LOGIN_NO_WORKSTATION');
                $error = 'NO_WORKSTATION_ASSIGNED';
            } elseif (\count($this->selectableShops()) > 1 && $this->chosenShop($request) === null) {
                // Le formulaire en présélectionne toujours une : on n'arrive
                // ici qu'avec un champ forgé, ou sur une boutique fermée entre
                // l'affichage et l'envoi. Ouvrir quand même la session aurait
                // laissé compter SANS boutique — et les relevés du jour se
                // seraient rattachés à rien, ce qui ne se voit qu'après coup.
                $this->store->audit($consultant->id, $consultant->role->value, 'LOGIN_NO_SHOP');
                $error = 'SHOP_REQUIRED';
            } else {
                $boutique = $this->chosenShop($request);

                $this->currentUser->login($consultant, null, $boutique?->id);
                $this->store->audit($consultant->id, $consultant->role->value, 'LOGIN', $this->currentUser->workstationId(), null, [
                    'shop' => $boutique?->code,
                ]);

                $reponse = $this->redirectToRoute('home');

                // La tablette REPOSE au même endroit demain. Se souvenir du
                // choix évite de le refaire deux fois par jour, et il reste
                // modifiable : le cookie ne fait que présélectionner.
                if ($boutique !== null) {
                    $reponse->headers->setCookie($this->shopCookie($request, $boutique->id));
                }

                return $reponse;
            }
        }

        return $this->render('security/login.html.twig', [
            'error' => $error,
            'shops' => $this->selectableShops(),
            'selectedShop' => $this->preselectedShopId($request),
        ]);
    }

    /**
     * Les boutiques que l'écran propose.
     *
     * Les FERMÉES n'y sont pas : une boutique désactivée en administration ne
     * doit pas pouvoir recevoir un comptage, et la voir dans la liste
     * inviterait à en poser un.
     *
     * @return list<\Merisu\Inventory\Domain\Shop>
     */
    private function selectableShops(): array
    {
        return $this->shops->all(activeOnly: true);
    }

    /**
     * La boutique choisie au formulaire.
     *
     * Un réseau d'une seule boutique n'a rien à demander : le choix serait un
     * geste de plus pour une réponse toujours identique. L'unique boutique est
     * alors retenue d'office.
     *
     * Un identifiant inconnu — champ forgé, ou boutique fermée entre
     * l'affichage et l'envoi — ne rend RIEN plutôt que la première venue :
     * mieux vaut une session sans boutique, que les écrans signaleront, qu'un
     * comptage silencieusement rattaché au mauvais endroit.
     */
    private function chosenShop(Request $request): ?\Merisu\Inventory\Domain\Shop
    {
        $ouvertes = $this->selectableShops();

        if (\count($ouvertes) === 1) {
            return $ouvertes[0];
        }

        $demandee = (string) $request->request->get('shopId', '');

        foreach ($ouvertes as $boutique) {
            if ($boutique->id === $demandee) {
                return $boutique;
            }
        }

        return null;
    }

    /** Le choix de la veille, s'il désigne encore une boutique ouverte. */
    private function preselectedShopId(Request $request): ?string
    {
        $memorise = (string) $request->cookies->get(self::SHOP_COOKIE, '');

        foreach ($this->selectableShops() as $boutique) {
            if ($boutique->id === $memorise) {
                return $boutique->id;
            }
        }

        return null;
    }

    private function shopCookie(Request $request, string $shopId): Cookie
    {
        return Cookie::create(self::SHOP_COOKIE, $shopId)
            ->withExpires(new \DateTimeImmutable('+1 year'))
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX)
            // `secure` suit la requête en cours, il n'est pas posé en dur :
            // l'application tourne aussi derrière un HTTP interne, où un
            // cookie « secure » serait simplement perdu sans rien annoncer.
            ->withSecure($request->isSecure());
    }

    /**
     * Connexion ADMINISTRATION, distincte de celle du poste.
     *
     * Deux portes d'entrée séparées : le vendeur au poste ne voit jamais
     * l'administration, et l'accès admin ne s'obtient pas depuis l'écran de
     * comptage. Un code de vendeur saisi ici est refusé, même s'il est valide.
     */
    #[Route('/admin/connexion', name: 'admin_login', methods: ['GET', 'POST'])]
    public function adminLogin(Request $request): Response
    {
        if ($this->currentUser->isAdmin()) {
            return $this->redirectToRoute('admin_home');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $secret = PinField::read($request);

            $byIp = $this->loginIpLimiter->create($request->getClientIp() ?? 'unknown');
            $global = $this->loginGlobalLimiter->create('login');

            if (!$byIp->consume()->isAccepted() || !$global->consume()->isAccepted()) {
                $this->store->audit('anonyme', 'ANONYMOUS', 'LOGIN_THROTTLED', null, null, [
                    'ip' => $request->getClientIp(),
                    'scope' => 'admin',
                ]);

                return $this->render('security/admin_login.html.twig', [
                    'error' => 'TOO_MANY_ATTEMPTS',
                ], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }

            $consultant = $this->consultants->authenticateByPin($secret);

            if ($consultant === null) {
                $error = 'INVALID_CREDENTIALS';
            } elseif (!$consultant->role->isAdmin()) {
                // Code valide mais sans droits : refusé ici, et tracé — une
                // tentative d'accès admin depuis un compte vendeur doit se voir.
                $this->store->audit($consultant->id, $consultant->role->value, 'ADMIN_LOGIN_REFUSED');
                $error = 'ROLE_NOT_ALLOWED';
            } else {
                $this->currentUser->login($consultant, $this->currentUser->workstationId());
                $this->store->audit($consultant->id, $consultant->role->value, 'ADMIN_LOGIN');

                return $this->redirectToRoute('admin_home');
            }
        }

        return $this->render('security/admin_login.html.twig', ['error' => $error]);
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

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('home'));
    }

    /** Changement de langue : mémorisé, il prime sur le paramètre d'administration. */
    #[Route('/langue', name: 'select_locale', methods: ['POST'])]
    public function selectLocale(Request $request): Response
    {
        $locale = Locale::tryFromLoose((string) $request->request->get('locale'));

        if ($locale !== null) {
            $request->getSession()->set(LocaleSubscriber::SESSION_KEY, $locale->value);
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('home'));
    }
}
