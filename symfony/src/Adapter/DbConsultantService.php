<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Service\PinHasher;
use Merisu\Inventory\Store\ConsultantStore;

/**
 * Consultants et postes administrés depuis l'application elle-même.
 *
 * ⚠️ Implémentation de REPLI, comme `LocalConsultantService` — mais celle-ci
 * lit une base plutôt que trois fiches écrites en dur, ce qui permet à une
 * boutique d'inscrire ses vrais vendeurs sans redéploiement.
 *
 * Elle ne remplace PAS le module « Consultant / Stanowisko » de l'hôte : le
 * jour où celui-ci est branché, l'alias de services.yaml pointe ailleurs et
 * cette classe sort du circuit sans qu'une ligne du reste de l'application ne
 * change. C'est tout l'intérêt de passer par l'interface.
 */
final readonly class DbConsultantService implements ConsultantServiceInterface
{
    public function __construct(
        private ConsultantStore $store,
        private PinHasher $hasher,
    ) {
    }

    /**
     * Connexion par identifiant + secret.
     *
     * Conservée pour respecter l'interface, mais l'application n'en fait pas
     * usage : le parcours réel est le code PIN seul, saisi au poste.
     */
    public function authenticate(string $login, string $secret): ?Consultant
    {
        $consultant = $this->authenticateByPin($secret);

        return $consultant !== null && $consultant->id === trim($login) ? $consultant : null;
    }

    public function authenticateByPin(string $pin): ?Consultant
    {
        $hash = $this->hasher->hash($pin);

        // Un code vide ne doit jamais ouvrir : sans ce garde-fou, il
        // rencontrerait les fiches dont l'empreinte est nulle.
        return $hash === null ? null : $this->store->byPinHash($hash);
    }

    public function consultant(string $id): ?Consultant
    {
        return $this->store->consultant($id);
    }

    public function consultants(): array
    {
        return $this->store->consultants();
    }

    public function workstations(): array
    {
        return $this->store->workstations(activeOnly: true);
    }

    public function workstation(string $id): ?Workstation
    {
        return $this->store->workstation($id);
    }

    public function assignedWorkstation(string $consultantId): ?Workstation
    {
        $poste = $this->store->consultant($consultantId)?->defaultWorkstationId;

        return $poste === null ? null : $this->workstation($poste);
    }
}
