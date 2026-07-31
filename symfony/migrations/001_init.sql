-- ═══════════════════════════════════════════════════════════════════════════
-- MERISU — Module Inventaire & Production — schéma initial (PostgreSQL)
--
-- Ce script est IDEMPOTENT : il peut être rejoué sans risque.
-- Il ne crée AUCUNE donnée métier ; le remplissage passe par le seed ou l'admin.
--
-- Les tables des modules EXISTANTS (consultants, postes, recettes) ne sont PAS
-- créées ici : elles appartiennent à ces modules et sont lues via les adaptateurs.
-- Les colonnes `consultant_id` / `workstation_id` sont donc de simples références
-- textuelles, sans clé étrangère vers un schéma que ce module ne possède pas.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS inv_settings (
  id                SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1), -- ligne unique
  opening_time      TEXT        NOT NULL DEFAULT '08:00',
  closing_time      TEXT        NOT NULL DEFAULT '22:00',
  timezone          TEXT        NOT NULL DEFAULT 'Europe/Warsaw',
  default_locale    TEXT        NOT NULL DEFAULT 'fr',
  photo_required    BOOLEAN     NOT NULL DEFAULT TRUE,
  photo_per_product BOOLEAN     NOT NULL DEFAULT FALSE,
  delta_tolerance   NUMERIC     NOT NULL DEFAULT 0.05,
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO inv_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

-- Produits : 8 emplacements génériques, renommables/activables en admin.
CREATE TABLE IF NOT EXISTS inv_product (
  id            TEXT PRIMARY KEY,
  code          TEXT    NOT NULL UNIQUE,           -- PRODUIT_1 … PRODUIT_8
  name          JSONB   NOT NULL DEFAULT '{}'::jsonb, -- { "fr": "...", "pl": "...", ... }
  unit          TEXT    NOT NULL DEFAULT 'pcs',
  active        BOOLEAN NOT NULL DEFAULT FALSE,
  waste_factor  NUMERIC NOT NULL DEFAULT 0 CHECK (waste_factor >= 0),
  rounding_step NUMERIC NOT NULL DEFAULT 1 CHECK (rounding_step > 0),
  rounding_mode TEXT    NOT NULL DEFAULT 'CEIL'
                CHECK (rounding_mode IN ('CEIL', 'FLOOR', 'NEAREST', 'NONE')),
  recipe_ref    TEXT,                              -- clé vers le module Recette
  sort_order    INTEGER NOT NULL DEFAULT 0
);

-- Matrice des seuils : pièces requises par (produit × jour de semaine).
-- `workstation_id` NULL = seuil global ; renseigné = seuil spécifique au poste.
CREATE TABLE IF NOT EXISTS inv_par_matrix (
  id              TEXT PRIMARY KEY,
  product_id      TEXT    NOT NULL REFERENCES inv_product(id) ON DELETE CASCADE,
  day_of_week     TEXT    NOT NULL
                  CHECK (day_of_week IN ('MON','TUE','WED','THU','FRI','SAT','SUN')),
  required_pieces NUMERIC NOT NULL CHECK (required_pieces >= 0),
  workstation_id  TEXT
);

-- Un seul seuil par (produit, jour, poste). COALESCE gère le NULL du seuil global.
CREATE UNIQUE INDEX IF NOT EXISTS inv_par_matrix_unique
  ON inv_par_matrix (product_id, day_of_week, COALESCE(workstation_id, ''));

-- Comptages physiques : une ligne par (jour, poste, produit, moment).
CREATE TABLE IF NOT EXISTS inv_count (
  id             TEXT PRIMARY KEY,
  business_date  DATE    NOT NULL,
  workstation_id TEXT    NOT NULL,
  consultant_id  TEXT    NOT NULL,
  product_id     TEXT    NOT NULL REFERENCES inv_product(id) ON DELETE CASCADE,
  moment         TEXT    NOT NULL CHECK (moment IN ('OPEN_0800', 'CLOSE_2200')),
  qty            NUMERIC NOT NULL CHECK (qty >= 0),
  produced_qty   NUMERIC,
  validated      BOOLEAN NOT NULL DEFAULT FALSE,
  validated_at   TIMESTAMPTZ,
  validated_by   TEXT,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS inv_count_unique
  ON inv_count (business_date, workstation_id, product_id, moment);
CREATE INDEX IF NOT EXISTS inv_count_by_date ON inv_count (business_date, workstation_id);

-- Photos jointes à un comptage (preuve du stock du soir).
CREATE TABLE IF NOT EXISTS inv_count_photo (
  id                 TEXT PRIMARY KEY,
  inventory_count_id TEXT NOT NULL REFERENCES inv_count(id) ON DELETE CASCADE,
  url                TEXT NOT NULL,
  taken_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS inv_count_photo_by_count
  ON inv_count_photo (inventory_count_id);

-- Plan de production figé à la validation du soir, visant le lendemain.
CREATE TABLE IF NOT EXISTS inv_production_plan (
  id                TEXT PRIMARY KEY,
  for_date          DATE    NOT NULL,
  product_id        TEXT    NOT NULL REFERENCES inv_product(id) ON DELETE CASCADE,
  workstation_id    TEXT    NOT NULL,
  required_pieces   NUMERIC NOT NULL,
  closing_stock     NUMERIC NOT NULL,
  qty_to_produce    NUMERIC NOT NULL,
  missing_threshold BOOLEAN NOT NULL DEFAULT FALSE,
  computed_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  status            TEXT    NOT NULL DEFAULT 'FROZEN'
                    CHECK (status IN ('DRAFT', 'FROZEN', 'CANCELLED'))
);

CREATE UNIQUE INDEX IF NOT EXISTS inv_production_plan_unique
  ON inv_production_plan (for_date, workstation_id, product_id);

-- Consommation réelle de matières, base du delta technique (§6).
CREATE TABLE IF NOT EXISTS inv_material_movement (
  id             TEXT PRIMARY KEY,
  business_date  DATE    NOT NULL,
  material_id    TEXT    NOT NULL,      -- référence le module Recette/Matières
  real_qty       NUMERIC NOT NULL,
  workstation_id TEXT,
  recorded_by    TEXT    NOT NULL,
  recorded_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  note           TEXT
);

CREATE INDEX IF NOT EXISTS inv_material_movement_by_date
  ON inv_material_movement (business_date);

-- Journal d'audit : qui a compté/validé/corrigé, quand, sur quel poste (§2).
CREATE TABLE IF NOT EXISTS inv_audit (
  id             TEXT PRIMARY KEY,
  at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  actor_id       TEXT NOT NULL,
  actor_role     TEXT NOT NULL,
  action         TEXT NOT NULL,
  workstation_id TEXT,
  business_date  DATE,
  details        JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS inv_audit_by_date ON inv_audit (business_date, at DESC);
