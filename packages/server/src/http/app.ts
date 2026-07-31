/** Assemblage de l'application Express. */

import express, { type NextFunction, type Request, type Response } from 'express';
import cors from 'cors';

import { adminRoutes } from '../routes/admin.js';
import { authRoutes } from '../routes/auth.js';
import { inventoryRoutes } from '../routes/inventory.js';
import { reportRoutes } from '../routes/reports.js';
import { AppError } from './errors.js';
import type { AppContext } from './context.js';

export function createApp(ctx: AppContext) {
  const app = express();

  app.use(
    cors({
      origin: ctx.config.corsOrigins.includes('*') ? true : ctx.config.corsOrigins,
    }),
  );
  // Limite élevée : les photos de comptage transitent en base64 (file hors-ligne).
  app.use(express.json({ limit: ctx.config.jsonBodyLimit }));

  app.get('/api/health', (_req, res) => {
    res.json({ status: 'ok', store: ctx.config.store });
  });

  app.use('/api/auth', authRoutes(ctx));
  app.use('/api', inventoryRoutes(ctx));
  app.use('/api/admin', adminRoutes(ctx));
  app.use('/api/reports', reportRoutes(ctx));

  // Photos de comptage.
  app.use(ctx.config.uploadPublicPath, express.static(ctx.config.uploadDir));

  app.use((_req, res) => {
    res.status(404).json({ error: { code: 'ROUTE_NOT_FOUND' } });
  });

  app.use((error: unknown, _req: Request, res: Response, _next: NextFunction) => {
    if (error instanceof AppError) {
      res.status(error.status).json({
        error: { code: error.code, details: error.details ?? null },
      });
      return;
    }

    // Erreur inattendue : on journalise côté serveur et on ne fuit rien au client.
    console.error('[api] erreur non gérée', error);
    res.status(500).json({ error: { code: 'INTERNAL_ERROR' } });
  });

  return app;
}
