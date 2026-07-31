/** Rapports admin (§6, §7.8) : delta technique, écarts nets, matrice, exports CSV. */

import { isIsoDate, isSupportedLocale, previousDay, type Locale } from '@merisu/domain';
import { Router } from 'express';

import { authenticate, requireRole } from '../auth/middleware.js';
import { asyncHandler } from '../http/asyncHandler.js';
import type { AppContext } from '../http/context.js';
import { badRequest } from '../http/errors.js';

export function reportRoutes(ctx: AppContext): Router {
  const router = Router();
  router.use(authenticate(ctx.config.authSecret), requireRole('ADMIN'));

  /** Période demandée ; par défaut les 7 derniers jours. */
  function parsePeriod(query: Record<string, unknown>): {
    from: string;
    to: string;
    workstationId?: string;
  } {
    const to = parseDate(query.to) ?? new Date().toISOString().slice(0, 10);
    const from = parseDate(query.from) ?? addDaysBack(to, 6);
    if (from > to) throw badRequest('INVALID_PERIOD');

    const workstationId =
      typeof query.workstationId === 'string' && query.workstationId !== ''
        ? query.workstationId
        : undefined;

    return workstationId ? { from, to, workstationId } : { from, to };
  }

  router.get(
    '/daily-net',
    asyncHandler(async (req, res) => {
      res.json(await ctx.reports.dailyNetReport(parsePeriod(req.query as Record<string, unknown>)));
    }),
  );

  router.get(
    '/delta',
    asyncHandler(async (req, res) => {
      res.json(await ctx.reports.deltaReport(parsePeriod(req.query as Record<string, unknown>)));
    }),
  );

  router.get(
    '/matrix',
    asyncHandler(async (req, res) => {
      res.json(await ctx.reports.matrixReport(parsePeriod(req.query as Record<string, unknown>)));
    }),
  );

  router.get(
    '/delta.csv',
    asyncHandler(async (req, res) => {
      const period = parsePeriod(req.query as Record<string, unknown>);
      const csv = await ctx.reports.deltaReportCsv(period, parseLocale(req.query.locale));

      sendCsv(res, `merisu-delta-${period.from}_${period.to}.csv`, csv);
    }),
  );

  router.get(
    '/matrix.csv',
    asyncHandler(async (req, res) => {
      const period = parsePeriod(req.query as Record<string, unknown>);
      const csv = await ctx.reports.matrixReportCsv(period, parseLocale(req.query.locale));

      sendCsv(res, `merisu-matrice-${period.from}_${period.to}.csv`, csv);
    }),
  );

  return router;
}

function parseDate(value: unknown): string | undefined {
  if (typeof value !== 'string' || value === '') return undefined;
  if (!isIsoDate(value)) throw badRequest('INVALID_DATE');
  return value;
}

function parseLocale(value: unknown): Locale {
  return typeof value === 'string' && isSupportedLocale(value) ? value : 'fr';
}

function addDaysBack(isoDate: string, days: number): string {
  let cursor = isoDate;
  for (let i = 0; i < days; i += 1) cursor = previousDay(cursor);
  return cursor;
}

function sendCsv(res: import('express').Response, fileName: string, csv: string): void {
  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="${fileName}"`);
  // BOM UTF-8 : sans lui, Excel affiche mal les accents des libellés FR/PL/IT/ES.
  res.send(`﻿${csv}`);
}
