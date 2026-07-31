/**
 * ADAPTATEUR — Module « Consultant / Poste de travail (Stanowisko) » EXISTANT.
 *
 * ⚠️ Ce module N'EST PAS à recoder. Ce fichier est le SEUL point de contact
 * entre Inventaire & Production et l'existant : tout le reste du code passe par
 * l'interface `ConsultantService` et ignore d'où viennent les utilisateurs.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TODO INTÉGRATION — à faire au branchement sur le vrai module :
 *   1. Écrire une classe implémentant `ConsultantService` qui interroge le
 *      module existant (appel HTTP, import de service, ou requête SQL sur ses
 *      tables — au choix selon ce qu'il expose).
 *   2. Faire correspondre les rôles du module existant aux rôles applicatifs
 *      CONSULTANT / ADMIN (voir `mapExternalRole` plus bas).
 *   3. Remplacer `LocalConsultantService` par cette implémentation dans
 *      `createConsultantService()` (fin de fichier).
 *   4. Si l'authentification est déjà gérée par le module existant, court-circuiter
 *      `authenticate()` pour valider son jeton au lieu du code PIN local.
 * ═══════════════════════════════════════════════════════════════════════════
 */

import type { Consultant, Role, Workstation } from '@merisu/domain';

export interface ConsultantService {
  /** Vérifie une identité et renvoie le consultant, ou null si refusé. */
  authenticate(credentials: { login: string; secret: string }): Promise<Consultant | null>;
  getConsultant(id: string): Promise<Consultant | null>;
  listConsultants(): Promise<Consultant[]>;
  listWorkstations(): Promise<Workstation[]>;
  getWorkstation(id: string): Promise<Workstation | null>;
  /** Poste auquel le consultant est affecté, s'il l'est. */
  getAssignedWorkstation(consultantId: string): Promise<Workstation | null>;
}

/**
 * Convertit un rôle du module existant vers un rôle applicatif.
 * TODO INTÉGRATION : compléter avec les libellés réels (ex. « kierownik », « manager »…).
 */
export function mapExternalRole(externalRole: string): Role {
  const normalized = externalRole.trim().toUpperCase();
  const adminAliases = ['ADMIN', 'ADMINISTRATOR', 'MANAGER', 'KIEROWNIK', 'OWNER'];
  return adminAliases.includes(normalized) ? 'ADMIN' : 'CONSULTANT';
}

/** Données locales servant tant que le vrai module n'est pas branché. */
export interface LocalConsultantRecord extends Consultant {
  /** Code PIN de démonstration. Le vrai module gérera l'authentification. */
  secret: string;
}

/**
 * Implémentation de repli : consultants et postes stockés localement (seed).
 * Elle permet de lancer et démontrer le module AVANT le branchement.
 */
export class LocalConsultantService implements ConsultantService {
  constructor(
    private readonly consultants: LocalConsultantRecord[],
    private readonly workstations: Workstation[],
  ) {}

  async authenticate(credentials: { login: string; secret: string }): Promise<Consultant | null> {
    const found = this.consultants.find(
      (c) => c.active && c.id.toLowerCase() === credentials.login.trim().toLowerCase(),
    );
    if (!found) return null;
    if (found.secret !== credentials.secret.trim()) return null;
    return stripSecret(found);
  }

  async getConsultant(id: string): Promise<Consultant | null> {
    const found = this.consultants.find((c) => c.id === id);
    return found ? stripSecret(found) : null;
  }

  async listConsultants(): Promise<Consultant[]> {
    return this.consultants.map(stripSecret);
  }

  async listWorkstations(): Promise<Workstation[]> {
    return this.workstations.filter((w) => w.active);
  }

  async getWorkstation(id: string): Promise<Workstation | null> {
    return this.workstations.find((w) => w.id === id) ?? null;
  }

  async getAssignedWorkstation(consultantId: string): Promise<Workstation | null> {
    const consultant = this.consultants.find((c) => c.id === consultantId);
    if (!consultant?.defaultWorkstationId) return null;
    return this.getWorkstation(consultant.defaultWorkstationId);
  }
}

function stripSecret(record: LocalConsultantRecord): Consultant {
  const { secret: _secret, ...consultant } = record;
  return consultant;
}

/**
 * Point de bascule unique vers le vrai module.
 *
 * TODO INTÉGRATION : quand le module existant est disponible, renvoyer ici
 * l'implémentation réelle, par exemple :
 *
 *   if (process.env.CONSULTANT_MODULE_URL) {
 *     return new HttpConsultantService(process.env.CONSULTANT_MODULE_URL);
 *   }
 */
export function createConsultantService(
  fallback: () => ConsultantService,
): ConsultantService {
  return fallback();
}
