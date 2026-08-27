<?php

declare(strict_types=1);

namespace Merisu\Inventory\Security;

use Merisu\Inventory\Adapter\Consultant;
use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Domain\TaskAccess;
use Merisu\Inventory\Domain\TaskTile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Identité de la requête courante, conservée en session.
 *
 * Volontairement minimal : l'authentification appartient au module Consultant
 * existant. Si l'application hôte possède déjà un pare-feu Symfony, remplacer
 * `login()` par la lecture de son utilisateur connecté — le reste du code ne
 * dépend que de `consultant()` et `workstationId()`.
 */
final class CurrentUser
{
    private const SESSION_KEY = 'merisu.consultant_id';
    private const WORKSTATION_KEY = 'merisu.workstation_id';
    private const SHOP_KEY = 'merisu.shop_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ConsultantServiceInterface $consultants,
    ) {
    }

    public function login(Consultant $consultant, ?string $workstationId, ?string $shopId = null): void
    {
        $session = $this->requestStack->getSession();
        // Nouvel identifiant de session à la connexion : parade classique à la
        // fixation de session.
        $session->migrate(true);
        $session->set(self::SESSION_KEY, $consultant->id);
        $session->set(self::WORKSTATION_KEY, $workstationId ?? $consultant->defaultWorkstationId);

        // La boutique est celle de la TABLETTE, pas celle de la personne : le
        // vendeur qui vient en renfort à Kraków compte le stock de Kraków. Le
        // poste, lui, reste attaché à la fiche — deux notions distinctes qu'un
        // seul champ aurait confondues.
        if ($shopId !== null && $shopId !== '') {
            $session->set(self::SHOP_KEY, $shopId);
        }
    }

    public function logout(): void
    {
        $this->requestStack->getSession()->invalidate();
    }

    public function consultant(): ?Consultant
    {
        $session = $this->requestStack->getSession();
        $id = $session->get(self::SESSION_KEY);

        return \is_string($id) ? $this->consultants->consultant($id) : null;
    }

    public function isLoggedIn(): bool
    {
        return $this->consultant() !== null;
    }

    public function role(): ?Role
    {
        return $this->consultant()?->role;
    }

    public function isAdmin(): bool
    {
        return $this->role()?->isAdmin() ?? false;
    }

    public function workstationId(): ?string
    {
        $value = $this->requestStack->getSession()->get(self::WORKSTATION_KEY);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function selectWorkstation(string $workstationId): void
    {
        $this->requestStack->getSession()->set(self::WORKSTATION_KEY, $workstationId);
    }

    /** La boutique où l'on travaille, ou null si le réseau n'en compte qu'une. */
    public function shopId(): ?string
    {
        $value = $this->requestStack->getSession()->get(self::SHOP_KEY);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function selectShop(string $shopId): void
    {
        $this->requestStack->getSession()->set(self::SHOP_KEY, $shopId);
    }

    /**
     * Exige de PILOTER quelque chose : un manager ou un administrateur.
     *
     * Distinct de `requireAdmin()`, et c'est le point : l'admin règle le
     * réseau, le manager pilote SES boutiques. Un écran qui laisse entrer un
     * manager doit donc, sans exception, filtrer ce qu'il montre — sinon il
     * lui livre les chiffres des boutiques voisines.
     *
     * @throws AccessDeniedHttpException
     */
    public function requireManager(): Consultant
    {
        $consultant = $this->requireConsultant();

        if (!$consultant->role->canManage()) {
            throw new AccessDeniedHttpException('MANAGER_REQUIRED');
        }

        return $consultant;
    }

    /** @throws AccessDeniedHttpException si personne n'est connecté */
    public function requireConsultant(): Consultant
    {
        return $this->consultant() ?? throw new AccessDeniedHttpException('MISSING_TOKEN');
    }

    /**
     * Exige le droit d'ouvrir une tuile du menu.
     *
     * Appelé par les contrôleurs, et pas seulement par le gabarit : une tuile
     * masquée à l'écran mais atteignable en tapant son adresse n'est pas une
     * permission, c'est une décoration. C'est ici que la restriction existe ;
     * le menu ne fait que la refléter.
     *
     * @throws AccessDeniedHttpException si la tuile n'est pas ouverte
     */
    public function requireTile(TaskTile $tile): Consultant
    {
        $consultant = $this->requireConsultant();

        if (!TaskAccess::allows($consultant->tiles, $tile, $consultant->role)) {
            throw new AccessDeniedHttpException('TILE_NOT_ALLOWED');
        }

        return $consultant;
    }

    /** @throws AccessDeniedHttpException si le rôle n'est pas ADMIN */
    public function requireAdmin(): Consultant
    {
        $consultant = $this->requireConsultant();

        if (!$consultant->role->isAdmin()) {
            throw new AccessDeniedHttpException('ROLE_NOT_ALLOWED');
        }

        return $consultant;
    }

    /**
     * Poste effectif de la requête.
     *
     * Un consultant est cantonné à son poste ; un administrateur peut viser
     * n'importe quel poste.
     */
    public function resolveWorkstation(?string $requested = null): string
    {
        $consultant = $this->requireConsultant();
        $asked = $requested !== null && $requested !== '' ? $requested : null;

        if ($consultant->role->isAdmin()) {
            return $asked ?? $this->workstationId() ?? throw new BadRequestHttpException('MISSING_WORKSTATION');
        }

        $own = $this->workstationId() ?? throw new BadRequestHttpException('NO_WORKSTATION_ASSIGNED');

        if ($asked !== null && $asked !== $own) {
            throw new AccessDeniedHttpException('WORKSTATION_NOT_ALLOWED');
        }

        return $own;
    }
}
