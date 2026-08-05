<?php

declare(strict_types=1);

namespace Merisu\Inventory\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;

/**
 * Création du schéma via l'abstraction DBAL, donc portable entre SQLite
 * (démonstration, zéro configuration) et PostgreSQL (production).
 *
 * Les tables des modules EXISTANTS (consultants, postes, recettes) ne sont pas
 * créées ici : elles appartiennent à ces modules et sont lues via les
 * adaptateurs. `consultant_id` et `workstation_id` sont donc de simples
 * références textuelles, sans clé étrangère vers un schéma qui ne nous
 * appartient pas.
 *
 * Le fichier `migrations/001_init.sql` reste fourni pour les équipes qui
 * préfèrent appliquer le schéma PostgreSQL en SQL brut.
 */
final class SchemaInstaller
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** Idempotent : ne crée que les tables absentes. */
    public function install(): void
    {
        $manager = $this->connection->createSchemaManager();
        $existing = $manager->listTableNames();
        $schema = new Schema();

        if (!\in_array('inv_settings', $existing, true)) {
            $t = $schema->createTable('inv_settings');
            $t->addColumn('id', 'smallint');            // ligne unique (id = 1)
            $t->addColumn('opening_time', 'string', ['length' => 5, 'default' => '08:00']);
            $t->addColumn('closing_time', 'string', ['length' => 5, 'default' => '22:00']);
            $t->addColumn('timezone', 'string', ['length' => 64, 'default' => 'Europe/Warsaw']);
            $t->addColumn('default_locale', 'string', ['length' => 5, 'default' => 'fr']);
            $t->addColumn('photo_required', 'boolean', ['default' => false]);
            $t->addColumn('photo_per_product', 'boolean', ['default' => false]);
            $t->addColumn('delta_tolerance', 'float', ['default' => 0.05]);
            $t->setPrimaryKey(['id']);
        }

        if (!\in_array('inv_product', $existing, true)) {
            $t = $schema->createTable('inv_product');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('code', 'string', ['length' => 32]);
            $t->addColumn('name', 'text');              // JSON : { "fr": "…", "pl": "…" }
            $t->addColumn('unit', 'string', ['length' => 16, 'default' => 'pcs']);
            $t->addColumn('active', 'boolean', ['default' => false]);
            $t->addColumn('waste_factor', 'float', ['default' => 0]);
            $t->addColumn('rounding_step', 'float', ['default' => 1]);
            $t->addColumn('rounding_mode', 'string', ['length' => 8, 'default' => 'CEIL']);
            $t->addColumn('recipe_ref', 'string', ['length' => 128, 'notnull' => false]);
            $t->addColumn('sort_order', 'integer', ['default' => 0]);
            $t->addColumn('count_mode', 'string', ['length' => 16, 'default' => 'PIECES']);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['code'], 'inv_product_code');
        }

        if (!\in_array('inv_par_matrix', $existing, true)) {
            $t = $schema->createTable('inv_par_matrix');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('product_id', 'string', ['length' => 64]);
            $t->addColumn('day_of_week', 'string', ['length' => 3]);
            $t->addColumn('required_pieces', 'float');
            // Chaîne vide plutôt que NULL : l'unicité d'un index portant une
            // colonne nullable n'est pas garantie de la même façon partout.
            $t->addColumn('workstation_id', 'string', ['length' => 64, 'default' => '']);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['product_id', 'day_of_week', 'workstation_id'], 'inv_par_unique');
        }

        if (!\in_array('inv_count', $existing, true)) {
            $t = $schema->createTable('inv_count');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('business_date', 'string', ['length' => 10]);
            $t->addColumn('workstation_id', 'string', ['length' => 64]);
            $t->addColumn('consultant_id', 'string', ['length' => 64]);
            $t->addColumn('product_id', 'string', ['length' => 64]);
            $t->addColumn('moment', 'string', ['length' => 16]);
            $t->addColumn('qty', 'float');
            $t->addColumn('produced_qty', 'float', ['notnull' => false]);
            $t->addColumn('validated', 'boolean', ['default' => false]);
            $t->addColumn('validated_at', 'string', ['length' => 32, 'notnull' => false]);
            $t->addColumn('validated_by', 'string', ['length' => 64, 'notnull' => false]);
            $t->addColumn('created_at', 'string', ['length' => 32]);
            $t->addColumn('updated_at', 'string', ['length' => 32]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['business_date', 'workstation_id', 'product_id', 'moment'], 'inv_count_unique');
            $t->addIndex(['business_date', 'workstation_id'], 'inv_count_by_date');
        }

        if (!\in_array('inv_count_photo', $existing, true)) {
            $t = $schema->createTable('inv_count_photo');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('inventory_count_id', 'string', ['length' => 64]);
            $t->addColumn('url', 'string', ['length' => 512]);
            $t->addColumn('taken_at', 'string', ['length' => 32]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['inventory_count_id'], 'inv_photo_by_count');
        }

        if (!\in_array('inv_production_plan', $existing, true)) {
            $t = $schema->createTable('inv_production_plan');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('for_date', 'string', ['length' => 10]);
            $t->addColumn('product_id', 'string', ['length' => 64]);
            $t->addColumn('workstation_id', 'string', ['length' => 64]);
            $t->addColumn('required_pieces', 'float');
            $t->addColumn('closing_stock', 'float');
            $t->addColumn('qty_to_produce', 'float');
            $t->addColumn('missing_threshold', 'boolean', ['default' => false]);
            $t->addColumn('computed_at', 'string', ['length' => 32]);
            $t->addColumn('status', 'string', ['length' => 16, 'default' => 'FROZEN']);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['for_date', 'workstation_id', 'product_id'], 'inv_plan_unique');
        }

        if (!\in_array('inv_material_movement', $existing, true)) {
            $t = $schema->createTable('inv_material_movement');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('business_date', 'string', ['length' => 10]);
            $t->addColumn('material_id', 'string', ['length' => 64]);
            $t->addColumn('real_qty', 'float');
            $t->addColumn('workstation_id', 'string', ['length' => 64, 'notnull' => false]);
            $t->addColumn('recorded_by', 'string', ['length' => 64]);
            $t->addColumn('recorded_at', 'string', ['length' => 32]);
            $t->addColumn('note', 'text', ['notnull' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['business_date'], 'inv_movement_by_date');
        }

        // ── Check-list du poste ─────────────────────────────────────────────
        // Deux tables, comme pour les comptages : le référentiel des points
        // (administrable) d'un côté, ce qui a été coché de l'autre. Les mêler
        // rendrait impossible de renommer un point sans réécrire l'historique.
        if (!\in_array('inv_checklist_item', $existing, true)) {
            $t = $schema->createTable('inv_checklist_item');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('section', 'string', ['length' => 16]);
            $t->addColumn('label', 'text');             // JSON : { "fr": "…", "pl": "…" }
            $t->addColumn('sort_order', 'integer', ['default' => 0]);
            $t->addColumn('active', 'boolean', ['default' => true]);
            $t->addColumn('required', 'boolean', ['default' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['section', 'sort_order'], 'inv_checklist_by_section');
        }

        if (!\in_array('inv_checklist_entry', $existing, true)) {
            $t = $schema->createTable('inv_checklist_entry');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('business_date', 'string', ['length' => 10]);
            $t->addColumn('workstation_id', 'string', ['length' => 64]);
            $t->addColumn('item_id', 'string', ['length' => 64]);
            $t->addColumn('checked', 'boolean', ['default' => false]);
            $t->addColumn('consultant_id', 'string', ['length' => 64]);
            $t->addColumn('checked_at', 'string', ['length' => 32]);
            $t->addColumn('note', 'text', ['notnull' => false]);
            $t->setPrimaryKey(['id']);
            // Un point, un jour, un poste : une seule ligne, mise à jour.
            $t->addUniqueIndex(['business_date', 'workstation_id', 'item_id'], 'inv_checklist_entry_unique');
            $t->addIndex(['business_date', 'workstation_id'], 'inv_checklist_by_date');
        }

        if (!\in_array('inv_audit', $existing, true)) {
            $t = $schema->createTable('inv_audit');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('at', 'string', ['length' => 32]);
            $t->addColumn('actor_id', 'string', ['length' => 64]);
            $t->addColumn('actor_role', 'string', ['length' => 16]);
            $t->addColumn('action', 'string', ['length' => 64]);
            $t->addColumn('workstation_id', 'string', ['length' => 64, 'notnull' => false]);
            $t->addColumn('business_date', 'string', ['length' => 10, 'notnull' => false]);
            $t->addColumn('details', 'text');
            $t->setPrimaryKey(['id']);
            $t->addIndex(['business_date'], 'inv_audit_by_date');
        }

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->ensureColumns();
        $this->ensureSettingsRow();
    }

    /**
     * Colonnes ajoutées après coup à des tables déjà installées.
     *
     * `install()` ne crée que les tables ABSENTES : une base en production ne
     * verrait jamais une colonne nouvelle. Sans cette étape, un déploiement
     * ajoutant un champ laisserait l'application en erreur sur la première
     * requête — panne découverte au poste, un matin, à 8 h.
     *
     * La présence de la colonne se teste par une requête, et non via
     * `listTableColumns()` : celui-ci échoue dès qu'une colonne de la table
     * porte un type que DBAL ne sait pas rattacher, et ferait alors tomber
     * tout le déploiement pour une colonne qui ne nous concerne même pas.
     *
     * Idempotent : chaque colonne n'est ajoutée que si elle manque.
     */
    private function ensureColumns(): void
    {
        $attendues = [
            'inv_product' => [
                // Ajoutée avec le comptage par contenant : les bases installées
                // avant ne l'ont pas, et tous leurs produits se comptent à
                // l'unité — ce que dit précisément la valeur par défaut.
                'count_mode' => "VARCHAR(16) DEFAULT 'PIECES' NOT NULL",
                // Catégorie de production, pour filtrer la liste du lendemain.
                // Vide par défaut : un produit sans catégorie reste visible.
                'category' => "VARCHAR(64) DEFAULT '' NOT NULL",
            ],
        ];

        foreach ($attendues as $table => $colonnes) {
            foreach ($colonnes as $nom => $definition) {
                if ($this->columnExists($table, $nom)) {
                    continue;
                }

                $this->connection->executeStatement(
                    \sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $nom, $definition),
                );
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $this->connection->fetchOne(\sprintf('SELECT %s FROM %s LIMIT 1', $column, $table));

            return true;
        } catch (\Doctrine\DBAL\Exception) {
            // Colonne absente — ou table absente, auquel cas `install()` vient
            // de la créer avec la colonne, et l'ALTER échouerait sans dommage.
            return false;
        }
    }

    private function ensureSettingsRow(): void
    {
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inv_settings');
        if ($count > 0) {
            return;
        }

        $defaults = \Merisu\Inventory\Domain\GeneralSettings::defaults();
        $this->connection->insert('inv_settings', [
            'id' => 1,
            'opening_time' => $defaults->openingTime,
            'closing_time' => $defaults->closingTime,
            'timezone' => $defaults->timezone,
            'default_locale' => $defaults->defaultLocale->value,
            'photo_required' => $defaults->photoRequired ? 1 : 0,
            'photo_per_product' => $defaults->photoPerProduct ? 1 : 0,
            'delta_tolerance' => $defaults->deltaTolerance,
        ]);
    }
}
