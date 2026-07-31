/**
 * Client API.
 *
 * Deux modes d'écriture :
 *   • `request`      — appel direct, échoue si le réseau est absent ;
 *   • `writeOrQueue` — tente l'appel, et bascule dans la file hors-ligne en cas
 *                      de coupure réseau (jamais en cas d'erreur métier 4xx).
 */

import type { CountMoment, DayOfWeek, Locale } from '@merisu/domain';

import { enqueue, flushQueue, type QueuedRequest } from '../offline/queue.js';
import type {
  AuditEntry,
  DaySheet,
  DeltaReport,
  GeneralSettings,
  LoginResponse,
  Material,
  MaterialMovement,
  MatrixReport,
  MeResponse,
  ParMatrixEntry,
  PlanPreview,
  PlanResponse,
  Product,
  ValidateResponse,
  Workstation,
} from './types.js';

const TOKEN_KEY = 'merisu.token';

/** Erreur portant le code renvoyé par l'API, traduit à l'affichage. */
export class ApiError extends Error {
  constructor(
    readonly code: string,
    readonly status: number,
    readonly details?: unknown,
  ) {
    super(code);
    this.name = 'ApiError';
  }
}

/** Coupure réseau (≠ erreur métier) : c'est ce qui déclenche la mise en file. */
export class NetworkError extends Error {
  constructor() {
    super('NETWORK');
    this.name = 'NetworkError';
  }
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null): void {
  if (token) localStorage.setItem(TOKEN_KEY, token);
  else localStorage.removeItem(TOKEN_KEY);
}

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
  options: { raw?: boolean } = {},
): Promise<T> {
  const token = getToken();

  let response: Response;
  try {
    response = await fetch(`/api${path}`, {
      method,
      headers: {
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
  } catch {
    throw new NetworkError();
  }

  if (!response.ok) {
    let code = 'INTERNAL_ERROR';
    let details: unknown = null;
    try {
      const payload = await response.json();
      code = payload?.error?.code ?? code;
      details = payload?.error?.details ?? null;
    } catch {
      // Réponse non JSON : on garde le code par défaut.
    }
    throw new ApiError(code, response.status, details);
  }

  if (options.raw) return (await response.text()) as T;
  return (await response.json()) as T;
}

/**
 * Écriture tolérante à la coupure réseau.
 * `queued: true` signale à l'IHM que la saisie est enregistrée localement.
 */
async function writeOrQueue<T>(
  method: 'PUT' | 'POST',
  path: string,
  body: unknown,
): Promise<{ data: T | null; queued: boolean }> {
  try {
    const data = await request<T>(method, path, body);
    return { data, queued: false };
  } catch (error) {
    if (error instanceof NetworkError) {
      await enqueue({ method, path, body });
      return { data: null, queued: true };
    }
    throw error;
  }
}

/** Rejeu de la file : utilisé au retour du réseau et au démarrage. */
export async function syncQueue(): Promise<{ sent: number; remaining: number }> {
  return flushQueue(async (queued: QueuedRequest) => {
    try {
      await request(queued.method, queued.path, queued.body);
    } catch (error) {
      if (error instanceof ApiError) throw new Error(`HTTP_${error.status}_${error.code}`);
      throw error;
    }
  });
}

export const api = {
  // ── Authentification ────────────────────────────────────────────────────
  login: (login: string, secret: string, workstationId?: string) =>
    request<LoginResponse>('POST', '/auth/login', { login, secret, workstationId }),
  me: () => request<MeResponse>('GET', '/auth/me'),
  selectWorkstation: (workstationId: string) =>
    request<{ token: string; workstationId: string }>('POST', '/auth/workstation', {
      workstationId,
    }),
  workstations: () => request<{ workstations: Workstation[] }>('GET', '/workstations'),
  /** Postes visibles avant connexion (identifiant + nom uniquement). */
  publicWorkstations: () =>
    request<{ workstations: Array<Pick<Workstation, 'id' | 'name'>> }>(
      'GET',
      '/auth/workstations',
    ),

  // ── Saisie ──────────────────────────────────────────────────────────────
  daySheet: (params: { date?: string; moment: CountMoment; workstationId?: string }) =>
    request<DaySheet>('GET', `/day-sheet?${query(params)}`),

  saveCounts: (body: {
    date: string;
    moment: CountMoment;
    workstationId?: string;
    entries: Array<{ productId: string; qty: number; producedQty?: number | null }>;
  }) => writeOrQueue<{ counts: DaySheet['counts'] }>('PUT', '/counts', body),

  addPhoto: (countId: string, body: { dataBase64: string; contentType: string; takenAt?: string }) =>
    writeOrQueue<{ count: DaySheet['counts'][number] }>('POST', `/counts/${countId}/photos`, body),

  /**
   * Photo désignée par sa clé métier — la variante utilisée hors-ligne :
   * l'identifiant technique du comptage n'existe pas encore à la prise de vue.
   */
  addPhotoByKey: (body: {
    date: string;
    moment: CountMoment;
    productId: string;
    workstationId?: string;
    dataBase64: string;
    contentType: string;
    takenAt?: string;
  }) => writeOrQueue<{ count: DaySheet['counts'][number] }>('POST', '/counts/photos', body),

  validate: (body: { date: string; moment: CountMoment; workstationId?: string }) =>
    writeOrQueue<ValidateResponse>('POST', '/validate', body),

  unlock: (body: {
    date: string;
    moment: CountMoment;
    workstationId?: string;
    reason?: string;
  }) => request<{ counts: DaySheet['counts'] }>('POST', '/unlock', body),

  // ── Production ──────────────────────────────────────────────────────────
  plan: (params: { forDate?: string; workstationId?: string }) =>
    request<PlanResponse>('GET', `/production-plan?${query(params)}`),
  planPreview: (params: { date?: string; workstationId?: string }) =>
    request<PlanPreview>('GET', `/production-plan/preview?${query(params)}`),

  // ── Administration ──────────────────────────────────────────────────────
  products: (activeOnly = false) =>
    request<{ products: Product[] }>('GET', `/admin/products?activeOnly=${activeOnly}`),
  updateProduct: (id: string, patch: Partial<Product>) =>
    request<{ product: Product }>('PUT', `/admin/products/${id}`, patch),

  parMatrix: () =>
    request<{ entries: ParMatrixEntry[]; days: DayOfWeek[] }>('GET', '/admin/par-matrix'),
  updateParMatrix: (
    entries: Array<{
      productId: string;
      dayOfWeek: DayOfWeek;
      requiredPieces: number | null;
      workstationId?: string | null;
    }>,
  ) => request<{ entries: ParMatrixEntry[] }>('PUT', '/admin/par-matrix', { entries }),

  settings: () =>
    request<{ settings: GeneralSettings; locales: Locale[] }>('GET', '/admin/settings'),
  updateSettings: (patch: Partial<GeneralSettings>) =>
    request<{ settings: GeneralSettings }>('PUT', '/admin/settings', patch),

  materials: () => request<{ materials: Material[] }>('GET', '/admin/materials'),

  materialMovements: (params: { from?: string; to?: string }) =>
    request<{ movements: MaterialMovement[] }>('GET', `/admin/material-movements?${query(params)}`),
  addMaterialMovement: (body: {
    date: string;
    materialId: string;
    realQty: number;
    note?: string;
  }) => request<{ movement: MaterialMovement }>('POST', '/admin/material-movements', body),

  audit: (params: { from?: string; to?: string; workstationId?: string; limit?: number }) =>
    request<{ entries: AuditEntry[] }>('GET', `/admin/audit?${query(params)}`),

  // ── Rapports ────────────────────────────────────────────────────────────
  deltaReport: (params: { from: string; to: string; workstationId?: string }) =>
    request<DeltaReport>('GET', `/reports/delta?${query(params)}`),
  matrixReport: (params: { from: string; to: string; workstationId?: string }) =>
    request<MatrixReport>('GET', `/reports/matrix?${query(params)}`),

  /**
   * Télécharge un export CSV.
   * Le téléchargement passe par `fetch` (et non par un simple lien) car la route
   * exige l'entête d'autorisation, qu'une navigation ne transporterait pas.
   */
  async downloadCsv(
    report: 'delta' | 'matrix',
    params: Record<string, unknown>,
    fileName: string,
  ): Promise<void> {
    const csv = await request<string>(
      'GET',
      `/reports/${report}.csv?${query(params)}`,
      undefined,
      { raw: true },
    );

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    link.click();
    URL.revokeObjectURL(url);
  },
};

function query(params: Record<string, unknown>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') search.set(key, String(value));
  }
  return search.toString();
}

export { request };
