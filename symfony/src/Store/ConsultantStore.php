<?php

declare(strict_types=1);

namespace Merisu\Inventory\Store;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Adapter\Consultant;
use Merisu\Inventory\Adapter\Workstation;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Domain\TaskTile;

/**
 * Accès aux consultants et aux postes stockés localement.
 *
 * ⚠️ Cette table est un REPLI, pas une reprise du module « Consultant /
 * Stanowisko ». Elle existe pour que l'application soit exploitable avant que
 * ce module ne soit branché — sans elle, les comptes seraient les trois fiches
 * de démonstration écrites en dur, et aucun vendeur réel ne pourrait se
 * connecter.
 *
 * Rien d'autre dans l'application ne connaît cette table : tout passe par
 * `ConsultantServiceInterface`, et le jour où le vrai module arrive, un alias
 * dans services.yaml suffit à la mettre hors circuit.
 */
final class ConsultantStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    // ── Postes de travail ───────────────────────────────────────────────────

    /** @return list<Workstation> */
    public function workstations(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM inv_workstation' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name';

        return array_map(
            static fn (array $r): Workstation => new Workstation(
                (string) $r['id'],
                (string) $r['name'],
                (bool) $r['active'],
                (string) ($r['shop_id'] ?? ''),
            ),
            $this->db->fetchAllAssociative($sql),
        );
    }

    public function workstation(string $id): ?Workstation
    {
        $r = $this->db->fetchAssociative('SELECT * FROM inv_workstation WHERE id = ?', [$id]);

        return $r === false ? null : new Workstation(
            (string) $r['id'],
            (string) $r['name'],
            (bool) $r['active'],
            (string) ($r['shop_id'] ?? ''),
        );
    }

    public function saveWorkstation(Workstation $poste, int $sortOrder = 0): void
    {
        $data = [
            'name' => $poste->name,
            'active' => $poste->active ? 1 : 0,
            'sort_order' => $sortOrder,
            'shop_id' => $poste->shopId,
        ];

        if ($this->db->update('inv_workstation', $data, ['id' => $poste->id]) === 0) {
            $this->db->insert('inv_workstation', $data + ['id' => $poste->id]);
        }
    }

    /**
     * Supprime un poste, sauf s'il est encore référencé.
     *
     * Un poste effacé alors que des comptages l'ont enregistré rendrait
     * l'historique illisible : les saisies porteraient l'identifiant d'un poste
     * dont plus personne ne connaît le nom. On refuse, et l'admin le désactive
     * s'il ne veut plus le voir proposé.
     */
    public function deleteWorkstation(string $id): bool
    {
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM inv_count WHERE workstation_id = ?', [$id]) > 0) {
            return false;
        }

        $this->db->delete('inv_workstation', ['id' => $id]);

        return true;
    }

    // ── Consultants ─────────────────────────────────────────────────────────

    /** @return list<Consultant> */
    public function consultants(): array
    {
        return array_map(
            $this->hydrate(...),
            $this->db->fetchAllAssociative('SELECT * FROM inv_consultant ORDER BY sort_order, last_name, first_name'),
        );
    }

    public function consultant(string $id): ?Consultant
    {
        $r = $this->db->fetchAssociative('SELECT * FROM inv_consultant WHERE id = ?', [$id]);

        return $r === false ? null : $this->hydrate($r);
    }

    /**
     * Consultant portant cette empreinte de code, s'il existe et s'il est actif.
     *
     * Recherche par égalité sur une colonne indexée : le temps de réponse ne
     * dépend ni du nombre de comptes ni de la position du bon, contrairement à
     * un parcours qui s'arrêterait à la correspondance.
     *
     * Le filtre sur `active` est ici et non chez l'appelant : un compte
     * désactivé dont le code fonctionnerait encore serait une porte ouverte
     * qu'on croirait fermée.
     */
    public function byPinHash(string $hash): ?Consultant
    {
        $r = $this->db->fetchAssociative(
            'SELECT * FROM inv_consultant WHERE pin_hash = ? AND active = 1',
            [$hash],
        );

        return $r === false ? null : $this->hydrate($r);
    }

    /** Un autre compte porte-t-il déjà ce code ? */
    public function pinTakenBy(string $hash, ?string $exceptId = null): ?string
    {
        $id = $this->db->fetchOne(
            'SELECT id FROM inv_consultant WHERE pin_hash = ?' . ($exceptId === null ? '' : ' AND id <> ?'),
            $exceptId === null ? [$hash] : [$hash, $exceptId],
        );

        return $id === false ? null : (string) $id;
    }

    /**
     * Enregistre un consultant.
     *
     * `$pinHash` à null LAISSE le code inchangé — c'est le cas courant, la
     * modification d'une fiche ne devant pas exiger de ressaisir un code que
     * l'administrateur ne connaît plus (il n'est plus lisible nulle part).
     */
    public function saveConsultant(Consultant $c, ?string $pinHash, int $sortOrder = 0): void
    {
        $data = [
            'first_name' => $c->firstName,
            'last_name' => $c->lastName,
            'email' => $c->email,
            'role' => $c->role->value,
            'default_workstation_id' => $c->defaultWorkstationId,
            'active' => $c->active ? 1 : 0,
            'locale' => $c->locale?->value,
            'shops' => json_encode($c->shops, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'workstations' => json_encode($c->workstations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            // Les tuiles autorisées, écrites par leur VALEUR : l'énumération
            // ne se sérialise pas d'elle-même, et une base doit rester lisible
            // par un humain qui ouvre la table.
            'tiles' => json_encode(
                array_map(static fn (TaskTile $t): string => $t->value, $c->tiles),
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            'sort_order' => $sortOrder,
        ];

        if ($pinHash !== null) {
            $data['pin_hash'] = $pinHash;
        }

        $exists = (int) $this->db->fetchOne('SELECT COUNT(*) FROM inv_consultant WHERE id = ?', [$c->id]) > 0;

        if ($exists) {
            $this->db->update('inv_consultant', $data, ['id' => $c->id]);
        } else {
            $this->db->insert('inv_consultant', $data + ['id' => $c->id]);
        }
    }

    /**
     * Supprime un consultant, sauf s'il a laissé des traces.
     *
     * Comme pour les postes : un comptage signé par un identifiant devenu
     * inconnu perd sa valeur de preuve. La fiche se désactive, elle ne
     * s'efface pas.
     */
    public function deleteConsultant(string $id): bool
    {
        $trace = (int) $this->db->fetchOne('SELECT COUNT(*) FROM inv_count WHERE consultant_id = ?', [$id])
            + (int) $this->db->fetchOne('SELECT COUNT(*) FROM inv_audit WHERE actor_id = ?', [$id]);

        if ($trace > 0) {
            return false;
        }

        $this->db->delete('inv_consultant', ['id' => $id]);

        return true;
    }

    /** Nombre de comptes ADMIN actifs — sert à ne pas se verrouiller dehors. */
    public function activeAdminCount(?string $exceptId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM inv_consultant WHERE role = ? AND active = 1';
        $params = [Role::Admin->value];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int) $this->db->fetchOne($sql, $params);
    }

    public function isEmpty(): bool
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM inv_consultant') === 0;
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): Consultant
    {
        return new Consultant(
            (string) $r['id'],
            (string) ($r['first_name'] ?? ''),
            (string) ($r['last_name'] ?? ''),
            Role::tryFrom((string) ($r['role'] ?? '')) ?? Role::Consultant,
            ($r['default_workstation_id'] ?? '') === '' ? null : (string) $r['default_workstation_id'],
            (bool) $r['active'],
            ($r['email'] ?? '') === '' ? null : (string) $r['email'],
            // Jamais de code en clair : il n'est plus stocké. La fiche profil
            // n'affiche donc plus « ••••23 », et c'est voulu — un code lisible
            // sur une tablette posée au comptoir n'est plus un secret.
            null,
            json_decode((string) ($r['shops'] ?? '[]'), true) ?: [],
            json_decode((string) ($r['workstations'] ?? '[]'), true) ?: [],
            Locale::tryFrom((string) ($r['locale'] ?? '')) ?: null,
            TaskTile::cleanList(json_decode((string) ($r['tiles'] ?? '[]'), true) ?: []),
        );
    }
}
