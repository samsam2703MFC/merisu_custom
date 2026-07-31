<?php

declare(strict_types=1);

namespace Merisu\Inventory\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Renvoie vers l'écran de connexion les visiteurs non authentifiés.
 *
 * Sans cela, ouvrir un raccourci vers une page protégée — ou simplement voir sa
 * session expirer en cours de service — aboutit à une page d'erreur 403, sans
 * moyen de se reconnecter. Le consultant est au poste, souvent pressé : il doit
 * retomber sur le formulaire, pas sur un cul-de-sac.
 *
 * Le 403 reste légitime dans l'autre cas : un consultant CONNECTÉ qui tente
 * d'atteindre l'administration doit bien être refusé, pas renvoyé au login.
 */
final class AccessDeniedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 0]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getThrowable() instanceof AccessDeniedHttpException) {
            return;
        }

        // Les appels de synchronisation hors-ligne attendent du JSON : les
        // rediriger vers une page HTML casserait le rejeu de la file d'attente.
        $request = $event->getRequest();
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return;
        }

        if ($this->currentUser->isLoggedIn()) {
            return; // droits insuffisants : le 403 est la bonne réponse
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('login')));
    }
}
