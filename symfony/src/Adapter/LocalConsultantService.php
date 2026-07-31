<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\Role;

/**
 * Implémentation de repli, active tant que le vrai module Consultant n'est pas
 * branché. Elle permet de lancer et de démontrer le module immédiatement.
 *
 * ⚠️ Les codes PIN par défaut sont triviaux et publiés dans le dépôt. Ils
 * DOIVENT être remplacés dès que l'application est joignable, via `.env.local` :
 *
 *     MERISU_ADMIN_PIN=418302
 *     MERISU_CONSULTANT1_PIN=735914
 *     MERISU_CONSULTANT2_PIN=260487
 *     MERISU_SHOW_DEMO_ACCOUNTS=0     # masque le rappel sur l'écran de connexion
 */
final class LocalConsultantService implements ConsultantServiceInterface
{
    /** @var array<string, array{name: string, role: Role, workstation: ?string, secret: string}> */
    private array $accounts;

    /**
     * Les codes viennent de la configuration, pas du code source : ils se
     * changent sans redéploiement et ne transitent plus par le dépôt Git.
     *
     * Les codes doivent rester UNIQUES entre comptes — ils constituent à eux
     * seuls l'identité, et l'audit deviendrait faux si deux personnes
     * partageaient le même.
     */
    public function __construct(string $adminPin, string $consultant1Pin, string $consultant2Pin)
    {
        $this->accounts = [
            'admin' => ['name' => 'Admin MERISU', 'role' => Role::Admin, 'workstation' => 'ws-1', 'secret' => $adminPin],
            'consultant1' => ['name' => 'Consultant 1', 'role' => Role::Consultant, 'workstation' => 'ws-1', 'secret' => $consultant1Pin],
            'consultant2' => ['name' => 'Consultant 2', 'role' => Role::Consultant, 'workstation' => 'ws-2', 'secret' => $consultant2Pin],
        ];
    }

    private const WORKSTATIONS = [
        'ws-1' => 'Stanowisko 1',
        'ws-2' => 'Stanowisko 2',
    ];

    public function authenticate(string $login, string $secret): ?Consultant
    {
        $key = strtolower(trim($login));
        $account = $this->accounts[$key] ?? null;

        // Comparaison à temps constant : réflexe à conserver dans la vraie
        // implémentation, où le secret sera un mot de passe haché.
        if ($account === null || !hash_equals($account['secret'], trim($secret))) {
            return null;
        }

        return $this->consultant($key);
    }

    /**
     * Connexion par code PIN seul.
     *
     * Tous les comptes sont parcourus sans interruption anticipée : sortir dès
     * la correspondance trouvée rendrait le temps de réponse dépendant de la
     * position du compte, ce qui laisse fuir de l'information.
     */
    public function authenticateByPin(string $pin): ?Consultant
    {
        $pin = trim($pin);
        $matched = null;

        foreach ($this->accounts as $id => $account) {
            if (hash_equals($account['secret'], $pin)) {
                $matched = $id;
            }
        }

        return $matched === null ? null : $this->consultant($matched);
    }

    public function consultant(string $id): ?Consultant
    {
        $account = $this->accounts[$id] ?? null;

        if ($account === null) {
            return null;
        }

        return new Consultant($id, $account['name'], $account['role'], $account['workstation'], true);
    }

    public function consultants(): array
    {
        return array_values(array_filter(array_map(
            fn (string $id): ?Consultant => $this->consultant($id),
            array_keys($this->accounts),
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
        $workstationId = $this->accounts[$consultantId]['workstation'] ?? null;

        return $workstationId === null ? null : $this->workstation($workstationId);
    }
}
