<?php

declare(strict_types=1);

namespace Merisu\Inventory\Security;

use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Store\Store;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Choix de la langue à chaque requête (§2).
 *
 * Ordre de priorité :
 *   1. choix explicite de l'utilisateur, mémorisé en session ;
 *   2. langue par défaut définie en administration ;
 *   3. détection depuis les préférences du navigateur, au premier lancement.
 *
 * Un choix explicite n'est donc jamais écrasé par le paramètre d'administration.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public const SESSION_KEY = 'merisu.locale';

    public function __construct(private readonly Store $store)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Priorité 20 : après l'initialisation de la session par Symfony,
        // avant la résolution du contrôleur.
        return [KernelEvents::REQUEST => [['onKernelRequest', 20]]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->hasSession()) {
            $chosen = Locale::tryFromLoose((string) $request->getSession()->get(self::SESSION_KEY, ''));
            if ($chosen !== null) {
                $request->setLocale($chosen->value);

                return;
            }
        }

        $default = $this->store->settings()->defaultLocale;

        $fromBrowser = Locale::tryFromLoose(
            $request->getPreferredLanguage(array_map(static fn (Locale $l): string => $l->value, Locale::all())),
        );

        $request->setLocale(($fromBrowser ?? $default)->value);
    }
}
