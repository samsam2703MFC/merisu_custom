/**
 * Erreurs applicatives.
 *
 * `code` est une clé stable, jamais un texte : la PWA la traduit dans la langue
 * du consultant (§2 — aucune chaîne en dur, y compris côté serveur).
 */

export class AppError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    readonly details?: unknown,
  ) {
    super(code);
    this.name = 'AppError';
  }
}

export const badRequest = (code: string, details?: unknown): AppError =>
  new AppError(400, code, details);
export const unauthorized = (code = 'UNAUTHORIZED'): AppError => new AppError(401, code);
export const forbidden = (code = 'FORBIDDEN'): AppError => new AppError(403, code);
export const notFound = (code = 'NOT_FOUND'): AppError => new AppError(404, code);
export const conflict = (code: string, details?: unknown): AppError =>
  new AppError(409, code, details);
