<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * ADAPTATEUR — Module « Consultant / Poste de travail (Stanowisko) » EXISTANT.
 *
 * ⚠️ Ce module N'EST PAS à recoder. Cette interface est le SEUL point de contact
 * entre Inventaire & Production et l'existant : tout le reste du code en dépend
 * et ignore d'où viennent les utilisateurs.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TODO INTÉGRATION — à faire au branchement sur le vrai module :
 *   1. Écrire une classe implémentant cette interface, qui interroge le module
 *      existant (service Symfony injecté, appel HTTP, ou requête Doctrine sur
 *      ses tables — au choix selon ce qu'il expose).
 *   2. Faire correspondre ses rôles aux rôles applicatifs CONSULTANT / ADMIN
 *      (voir RoleMapper).
 *   3. Déclarer votre implémentation à la place de LocalConsultantService dans
 *      `config/services.yaml` — c'est le seul fichier à modifier.
 *   4. Si l'application hôte gère déjà l'authentification (pare-feu Symfony),
 *      court-circuiter `authenticate()` et alimenter la session depuis son
 *      utilisateur connecté.
 * ═══════════════════════════════════════════════════════════════════════════
 */
interface ConsultantServiceInterface
{
    /** Vérifie une identité et renvoie le consultant, ou null si refusé. */
    public function authenticate(string $login, string $secret): ?Consultant;

    public function consultant(string $id): ?Consultant;

    /** @return list<Consultant> */
    public function consultants(): array;

    /** @return list<Workstation> postes actifs */
    public function workstations(): array;

    public function workstation(string $id): ?Workstation;

    /** Poste auquel le consultant est affecté, s'il l'est. */
    public function assignedWorkstation(string $consultantId): ?Workstation;
}
