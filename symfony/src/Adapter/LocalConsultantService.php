<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\Role;

/**
 * Implémentation de repli, active tant que le vrai module Consultant n'est pas
 * branché. Elle permet de lancer et de démontrer le module immédiatement.
 *
 * ⚠️ Les codes PIN sont triviaux : ne jamais déployer en production avec.
 */
final class LocalConsultantService implements ConsultantServiceInterface
{
    /** @var array<string, array{name: string, role: Role, workstation: ?string, secret: string}> */
    private const ACCOUNTS = [
        'admin' => ['name' => 'Admin MERISU', 'role' => Role::Admin, 'workstation' => 'ws-1', 'secret' => '0000'],
        'consultant1' => ['name' => 'Consultant 1', 'role' => Role::Consultant, 'workstation' => 'ws-1', 'secret' => '1111'],
        'consultant2' => ['name' => 'Consultant 2', 'role' => Role::Consultant, 'workstation' => 'ws-2', 'secret' => '2222'],
    ];

    private const WORKSTATIONS = [
        'ws-1' => 'Stanowisko 1',
        'ws-2' => 'Stanowisko 2',
    ];

    public function authenticate(string $login, string $secret): ?Consultant
    {
        $key = strtolower(trim($login));
        $account = self::ACCOUNTS[$key] ?? null;

        // Comparaison à temps constant : réflexe à conserver dans la vraie
        // implémentation, où le secret sera un mot de passe haché.
        if ($account === null || !hash_equals($account['secret'], trim($secret))) {
            return null;
        }

        return $this->consultant($key);
    }

    public function consultant(string $id): ?Consultant
    {
        $account = self::ACCOUNTS[$id] ?? null;

        if ($account === null) {
            return null;
        }

        return new Consultant($id, $account['name'], $account['role'], $account['workstation'], true);
    }

    public function consultants(): array
    {
        return array_values(array_filter(array_map(
            fn (string $id): ?Consultant => $this->consultant($id),
            array_keys(self::ACCOUNTS),
        )));
    }

    public function workstations(): array
    {
        $out = [];
        foreach (self::WORKSTATIONS as $id => $name) {
            $out[] = new Workstation($id, $name, true);
        }

        return $out;
    }

    public function workstation(string $id): ?Workstation
    {
        return isset(self::WORKSTATIONS[$id]) ? new Workstation($id, self::WORKSTATIONS[$id], true) : null;
    }

    public function assignedWorkstation(string $consultantId): ?Workstation
    {
        $workstationId = self::ACCOUNTS[$consultantId]['workstation'] ?? null;

        return $workstationId === null ? null : $this->workstation($workstationId);
    }
}
