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
            $t->addColumn('monthly_tiramisu_target', 'integer', ['default' => 0]);
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
            $t->addColumn('container_type', 'string', ['length' => 16, 'default' => 'TUB']);
            $t->addColumn('nature', 'string', ['length' => 16, 'default' => 'COMPOSED']);
            $t->addColumn('count_morning', 'boolean', ['default' => true]);
            $t->addColumn('count_evening', 'boolean', ['default' => true]);
            $t->addColumn('count_frequency', 'integer', ['default' => 7]);
            $t->addColumn('supplier_source', 'string', ['length' => 16, 'default' => 'CENTRAL']);
            $t->addColumn('supplier_ref', 'string', ['length' => 64, 'default' => '']);
            $t->addColumn('supplier_name', 'string', ['length' => 120, 'default' => '']);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['code'], 'inv_product_code');
        }

        /*
          Postes de travail (Stanowisko) et consultants.

          Ces deux tables n'existent QUE tant que le module « Consultant /
          Stanowisko » de l'hôte n'est pas branché. Elles alimentent
          `DbConsultantService`, qui se substitue en un alias à la vraie
          implémentation le jour où celle-ci arrive — voir services.yaml.

          Le code PIN n'est stocké que sous forme d'empreinte, et l'index
          d'unicité fait respecter la contrainte annoncée par
          `ConsultantServiceInterface` : deux vendeurs partageant un code
          partageraient une identité, et l'audit deviendrait faux.
        */
        if (!\in_array('inv_workstation', $existing, true)) {
            $t = $schema->createTable('inv_workstation');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('name', 'string', ['length' => 128]);
            $t->addColumn('active', 'boolean', ['default' => true]);
            $t->addColumn('sort_order', 'integer', ['default' => 0]);
            $t->setPrimaryKey(['id']);
        }

        if (!\in_array('inv_consultant', $existing, true)) {
            $t = $schema->createTable('inv_consultant');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('first_name', 'string', ['length' => 64, 'default' => '']);
            $t->addColumn('last_name', 'string', ['length' => 64, 'default' => '']);
            $t->addColumn('email', 'string', ['length' => 190, 'notnull' => false]);
            $t->addColumn('role', 'string', ['length' => 16, 'default' => 'CONSULTANT']);
            // Empreinte HMAC, jamais le code lui-même. Voir PinHasher.
            $t->addColumn('pin_hash', 'string', ['length' => 64, 'notnull' => false]);
            $t->addColumn('default_workstation_id', 'string', ['length' => 64, 'notnull' => false]);
            $t->addColumn('active', 'boolean', ['default' => true]);
            $t->addColumn('locale', 'string', ['length' => 5, 'notnull' => false]);
            $t->addColumn('shops', 'text', ['default' => '[]']);           // JSON
            $t->addColumn('workstations', 'text', ['default' => '[]']);    // JSON
            // Tuiles du menu ouvertes à cette personne. VIDE = toutes : les
            // fiches créées avant cette colonne ne doivent pas se retrouver
            // sans aucun droit du jour au lendemain.
            $t->addColumn('tiles', 'text', ['default' => '[]']);            // JSON
            $t->addColumn('sort_order', 'integer', ['default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['pin_hash'], 'inv_consultant_pin');
        }

        /*
          Liste et ORDRE des catégories de production.

          La catégorie reste portée par le produit, sous forme de texte : c'est
          ce champ qui fait foi. Cette table ne tient que la liste et son
          ordre — passer par des identifiants aurait obligé à migrer les fiches
          existantes pour un gain nul, une catégorie n'ayant rien d'autre
          qu'un nom.

          Le nom EST la clé : c'est lui qui relie la catégorie aux produits, et
          deux lignes de même nom n'auraient aucun sens.
        */
        if (!\in_array('inv_category', $existing, true)) {
            $t = $schema->createTable('inv_category');
            $t->addColumn('name', 'string', ['length' => 64]);
            $t->addColumn('sort_order', 'integer', ['default' => 0]);
            $t->addColumn('nature', 'string', ['length' => 16, 'default' => 'COMPOSED']);
            $t->setPrimaryKey(['name']);
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

        // Lignes de production FAITES, signées au code PIN.
        //
        // Une table à part, et non deux colonnes sur `inv_production_plan` :
        // le plan se supprime et se réinsère en bloc à chaque figeage, et une
        // revalidation du comptage du soir aurait effacé le travail de
        // l'atelier. Ici, le plan peut se recalculer sans que douze tiramisus
        // sortis à 14 h 32 cessent d'être sortis.
        if (!\in_array('inv_production_done', $existing, true)) {
            $t = $schema->createTable('inv_production_done');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('for_date', 'string', ['length' => 10]);
            $t->addColumn('workstation_id', 'string', ['length' => 64]);
            $t->addColumn('product_id', 'string', ['length' => 64]);
            // La quantité PORTÉE PAR LE PLAN au moment de la signature : un
            // plan refigé plus tard changerait sinon le chiffre sous la
            // signature, sans que personne ait rien touché.
            $t->addColumn('qty', 'float', ['default' => 0]);
            $t->addColumn('consultant_id', 'string', ['length' => 64]);
            $t->addColumn('done_at', 'string', ['length' => 32]);
            $t->setPrimaryKey(['id']);
            // Une ligne, un jour, un poste : une seule signature, reprise.
            $t->addUniqueIndex(['for_date', 'workstation_id', 'product_id'], 'inv_production_done_unique');
            $t->addIndex(['for_date', 'workstation_id'], 'inv_production_done_by_date');
        }

        /*
          Objectifs par boutique — indicateurs et seuils.

          Deux tables : le CATALOGUE des indicateurs, qui ne dépend ni du mois
          ni de la boutique, et les SEUILS, qui en dépendent des deux. Tout
          mettre dans une seule aurait recopié le libellé et l'unité de
          « chiffre d'affaires » douze fois par an et par boutique — et les
          aurait laissés diverger.

          La clé de l'indicateur suit le format de l'hôte (`metric_key`) :
          c'est elle qui reliera le local au réseau au branchement.
        */
        if (!\in_array('inv_shop_metric', $existing, true)) {
            $t = $schema->createTable('inv_shop_metric');
            $t->addColumn('metric_key', 'string', ['length' => 64]);
            $t->addColumn('label', 'string', ['length' => 120]);
            $t->addColumn('unit', 'string', ['length' => 24, 'default' => '']);
            // Un objectif de vente se dépasse, un temps d'attente se réduit.
            // Sans ce drapeau, l'écran colorerait du mauvais côté.
            $t->addColumn('lower_is_better', 'boolean', ['default' => false]);
            $t->addColumn('sort_order', 'integer', ['default' => 0]);
            $t->setPrimaryKey(['metric_key']);
        }

        if (!\in_array('inv_shop_target', $existing, true)) {
            $t = $schema->createTable('inv_shop_target');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('shop_id', 'string', ['length' => 64]);
            $t->addColumn('year', 'integer');
            $t->addColumn('month', 'smallint');
            $t->addColumn('metric_key', 'string', ['length' => 64]);
            $t->addColumn('threshold_1', 'float', ['default' => 0]);
            $t->addColumn('threshold_2', 'float', ['default' => 0]);
            $t->addColumn('threshold_3', 'float', ['default' => 0]);
            $t->addColumn('author_id', 'string', ['length' => 64, 'default' => '']);
            $t->addColumn('updated_at', 'string', ['length' => 32, 'default' => '']);
            $t->setPrimaryKey(['id']);
            // Une boutique, un mois, un indicateur : une seule ligne, reprise.
            $t->addUniqueIndex(['shop_id', 'year', 'month', 'metric_key'], 'inv_shop_target_unique');
            $t->addIndex(['shop_id', 'year', 'month'], 'inv_shop_target_month');
        }

        /*
          Postes RH, niveaux et compétences.

          ⚠️ Le poste RH n'est PAS le poste de travail (`inv_workstation`).
          L'un est la fonction qu'on occupe, l'autre l'endroit où l'on compte.
          Deux tables séparées, et deux vocabulaires séparés à l'écran : les
          confondre aurait fait dépendre le plan de production d'une promotion.
        */
        if (!\in_array('inv_job_position', $existing, true)) {
            $t = $schema->createTable('inv_job_position');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('name', 'string', ['length' => 120]);
            $t->addColumn('description', 'text', ['notnull' => false]);
            $t->addColumn('sort_order', 'integer', ['default' => 0]);
            $t->setPrimaryKey(['id']);
        }

        if (!\in_array('inv_position_level', $existing, true)) {
            $t = $schema->createTable('inv_position_level');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('position_id', 'string', ['length' => 64]);
            $t->addColumn('name', 'string', ['length' => 120]);
            $t->addColumn('description', 'text', ['notnull' => false]);
            // La PROGRESSION, et non l'ordre de création : « débutant »
            // précède « confirmé » même si on a saisi le second en premier.
            $t->addColumn('level_order', 'integer', ['default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['position_id'], 'inv_position_level_by_position');
        }

        if (!\in_array('inv_competency', $existing, true)) {
            $t = $schema->createTable('inv_competency');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('name', 'string', ['length' => 190]);
            $t->addColumn('category', 'string', ['length' => 120, 'default' => '']);
            $t->addColumn('subcategory', 'string', ['length' => 120, 'default' => '']);
            // Comment on constate qu'elle est acquise. Facultatif : une
            // boutique qui n'a pas formalisé ses vérifications doit pouvoir
            // tenir la liste quand même.
            $t->addColumn('verification_method', 'text', ['notnull' => false]);
            $t->addColumn('sort_order', 'integer', ['default' => 0]);
            $t->setPrimaryKey(['id']);
        }

        // Affectation : un poste ET son niveau, jamais l'un sans l'autre —
        // c'est le couple qu'attend l'hôte (`position_id`, `level_id`).
        if (!\in_array('inv_employee_position', $existing, true)) {
            $t = $schema->createTable('inv_employee_position');
            $t->addColumn('consultant_id', 'string', ['length' => 64]);
            $t->addColumn('position_id', 'string', ['length' => 64]);
            $t->addColumn('level_id', 'string', ['length' => 64]);
            $t->addColumn('assigned_at', 'string', ['length' => 32, 'default' => '']);
            // Un poste par personne : une promotion REMPLACE, elle n'ajoute
            // pas. Deux postes simultanés rendraient « son niveau » ambigu.
            $t->setPrimaryKey(['consultant_id']);
        }

        if (!\in_array('inv_employee_competency', $existing, true)) {
            $t = $schema->createTable('inv_employee_competency');
            $t->addColumn('consultant_id', 'string', ['length' => 64]);
            $t->addColumn('competency_id', 'string', ['length' => 64]);
            $t->addColumn('acquired_at', 'string', ['length' => 32, 'default' => '']);
            $t->setPrimaryKey(['consultant_id', 'competency_id']);
        }

        /*
          Identifiants de la caisse, saisis en administration.

          Une seule ligne : une installation ne parle qu'à une caisse.

          Le secret est CHIFFRÉ, avec une clé dérivée d'APP_SECRET, qui vit
          hors de la base. Une base dérobée seule ne livre donc pas de quoi
          appeler la caisse — même protection que les codes PIN, et pour la
          même raison. Les deux autres valeurs ne sont pas des secrets : un
          identifiant client et un numéro d'organisation ne servent à rien
          sans lui.
        */
        if (!\in_array('inv_pos_credential', $existing, true)) {
            $t = $schema->createTable('inv_pos_credential');
            $t->addColumn('id', 'smallint');
            $t->addColumn('client_id', 'string', ['length' => 190, 'default' => '']);
            $t->addColumn('client_secret', 'text', ['notnull' => false]);
            $t->addColumn('organization_id', 'string', ['length' => 64, 'default' => '']);
            $t->addColumn('base_url', 'string', ['length' => 190, 'default' => '']);
            $t->addColumn('updated_at', 'string', ['length' => 32, 'default' => '']);
            $t->setPrimaryKey(['id']);
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
            $t->addColumn('status', 'string', ['length' => 16, 'default' => 'PENDING']);
            $t->addColumn('photo_path', 'string', ['length' => 255, 'notnull' => false]);
            $t->addColumn('consultant_id', 'string', ['length' => 64]);
            $t->addColumn('checked_at', 'string', ['length' => 32]);
            $t->addColumn('note', 'text', ['notnull' => false]);
            $t->setPrimaryKey(['id']);
            // Un point, un jour, un poste : une seule ligne, mise à jour.
            $t->addUniqueIndex(['business_date', 'workstation_id', 'item_id'], 'inv_checklist_entry_unique');
            $t->addIndex(['business_date', 'workstation_id'], 'inv_checklist_by_date');
        }

        // Note du jour : les consignes de marque affichées au menu des tâches.
        // Rédigées en administration, dans les quatre langues — une consigne
        // de marque évolue, et un déploiement par phrase n'aurait rien changé.
        if (!\in_array('inv_day_note', $existing, true)) {
            $t = $schema->createTable('inv_day_note');
            $t->addColumn('id', 'string', ['length' => 64]);
            $t->addColumn('heading', 'text');          // JSON : { "fr": "…", … }
            $t->addColumn('body', 'text');             // JSON : { "fr": "…", … }
            $t->addColumn('sort_order', 'integer', ['default' => 0]);
            $t->addColumn('active', 'boolean', ['default' => true]);
            $t->setPrimaryKey(['id']);
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

        /*
          Correction météo du stock minimum, en pourcentage.

          En base et non dans le code (§2) : le coefficient d'une averse à
          Varsovie n'est pas celui d'une averse à Palerme, et l'atelier doit
          pouvoir l'ajuster après une saison sans attendre un déploiement.

          Le type EST la clé : deux lignes « pluie » n'auraient aucun sens.
        */
        if (!\in_array('inv_weather_ratio', $existing, true)) {
            $t = $schema->createTable('inv_weather_ratio');
            $t->addColumn('kind', 'string', ['length' => 16]);
            $t->addColumn('percent', 'float', ['default' => 0]);
            $t->setPrimaryKey(['kind']);
        }

        /*
          Nomenclatures : ce qu'une unité de produit consomme.

          La quantité est TOUJOURS ramenée à l'unité, jamais gardée « pour une
          fournée de vingt » : sans cela, chaque calcul devrait connaître aussi
          le rendement, et une fournée passée de vingt à vingt-quatre parts
          aurait faussé tout l'historique sans que rien ne le signale.

          Le couple (produit, matière) EST la clé : une matière citée deux fois
          pour le même produit se règle à la saisie, en additionnant.
        */
        if (!\in_array('inv_recipe_line', $existing, true)) {
            $t = $schema->createTable('inv_recipe_line');
            $t->addColumn('product_id', 'string', ['length' => 64]);
            $t->addColumn('material_id', 'string', ['length' => 64]);
            $t->addColumn('qty_per_unit', 'float', ['default' => 0]);
            $t->setPrimaryKey(['product_id', 'material_id']);
        }

        /*
          Temps ATTENDU pour chaque jour de la semaine.

          Par jour, et non un réglage unique : la météo change d'un jour à
          l'autre, et poser « pluie » pour toute la semaine ferait produire
          lundi comme dimanche. C'est une PRÉVISION que l'atelier saisit, pas
          une mesure — personne ne connaît le temps de jeudi depuis une base de
          données.

          Le jour EST la clé : deux lignes « mardi » n'auraient aucun sens.
        */
        if (!\in_array('inv_day_weather', $existing, true)) {
            $t = $schema->createTable('inv_day_weather');
            $t->addColumn('day_of_week', 'string', ['length' => 3]);
            $t->addColumn('kind', 'string', ['length' => 16, 'default' => 'CLOUDY']);
            $t->setPrimaryKey(['day_of_week']);
        }

        /*
          File d'envoi vers le système hôte — patron « boîte d'envoi ».

          En base, et non en mémoire : la validation d'un comptage y écrit dans
          la même transaction que le comptage lui-même. Ou les deux tiennent,
          ou aucun — une file en mémoire aurait perdu la remontée au premier
          redémarrage, sans que rien ne le signale.

          Rien n'y est jamais effacé. Une ligne envoyée reste horodatée : c'est
          la preuve qu'un comptage réel a été transmis (§5). Une ligne
          abandonnée garde sa charge utile et sa dernière erreur.
        */
        if (!\in_array('inv_sync_outbox', $existing, true)) {
            $t = $schema->createTable('inv_sync_outbox');
            $t->addColumn('id', 'integer', ['autoincrement' => true]);
            $t->addColumn('kind', 'string', ['length' => 32]);
            $t->addColumn('payload', 'text');
            $t->addColumn('status', 'string', ['length' => 16, 'default' => 'PENDING']);
            $t->addColumn('attempts', 'integer', ['default' => 0]);
            $t->addColumn('last_error', 'text', ['notnull' => false]);
            $t->addColumn('created_at', 'string', ['length' => 32]);
            $t->addColumn('sent_at', 'string', ['length' => 32, 'notnull' => false]);
            $t->addColumn('next_attempt_at', 'string', ['length' => 32, 'notnull' => false]);
            $t->setPrimaryKey(['id']);
            // Le vidage de file ne lit que les lignes en attente et dues :
            // sans index, il parcourrait tout l'historique des envois.
            $t->addIndex(['status', 'next_attempt_at'], 'inv_sync_outbox_due');
        }

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }

        /*
          `COMPOSED` était l'ancien nom du produit en vente, du temps où la
          nature n'avait que deux valeurs.

          `ProductNature::fromLoose` le relit déjà comme `SALE`, donc rien n'est
          cassé sans cette normalisation — mais une base qui porte deux noms
          pour la même chose se lit mal, et une requête écrite à la main
          passerait à côté de la moitié des lignes. Idempotent.
        */
        foreach (['inv_product', 'inv_category'] as $table) {
            if (\in_array($table, $existing, true)) {
                $this->connection->executeStatement(
                    "UPDATE {$table} SET nature = 'SALE' WHERE nature = 'COMPOSED'",
                );
            }
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
            'inv_settings' => [
                // Objectif de la jauge tiramisu, ajouté avec l'écran Réseau.
                // 0 par défaut : aucun objectif, donc aucune jauge — jamais
                // une barre pleine inventée pour les bases déjà en service.
                'monthly_tiramisu_target' => 'INTEGER DEFAULT 0 NOT NULL',
            ],
            'inv_product' => [
                // Ajoutée avec le comptage par contenant : les bases installées
                // avant ne l'ont pas, et tous leurs produits se comptent à
                // l'unité — ce que dit précisément la valeur par défaut.
                'count_mode' => "VARCHAR(16) DEFAULT 'PIECES' NOT NULL",
                // Catégorie de production, pour filtrer la liste du lendemain.
                // Vide par défaut : un produit sans catégorie reste visible.
                'category' => "VARCHAR(64) DEFAULT '' NOT NULL",
                // Mentions de l'étiquette de production, ajoutées après coup.
                // Durée de vie à 0 = non renseignée : aucune DLC n'est
                // imprimée plutôt qu'une date fausse.
                'shelf_life_days' => 'INTEGER DEFAULT 0 NOT NULL',
                'ingredients' => "TEXT DEFAULT '{}' NOT NULL",
                'allergens' => "TEXT DEFAULT '{}' NOT NULL",
                // Forme du contenant, ajoutée avec l'icône de la liste des
                // produits. Le bac par défaut : c'est la forme la plus
                // courante, et une base déjà en service n'a rien à ressaisir.
                'container_type' => "VARCHAR(16) DEFAULT 'TUB' NOT NULL",
                // Matière première ou composition. La composition par
                // défaut : les bases déjà en service ne contiennent que des
                // tiramisus, et basculer d'office les aurait TOUS retirés
                // du plan de production au premier déploiement.
                'nature' => "VARCHAR(16) DEFAULT 'COMPOSED' NOT NULL",
                // Rythme de comptage. Matin et soir tous les jours par
                // défaut : le réglage qui ne retire rien, et celui sous
                // lequel toutes les bases déjà en service tournaient.
                'count_morning' => 'BOOLEAN DEFAULT 1 NOT NULL',
                'count_evening' => 'BOOLEAN DEFAULT 1 NOT NULL',
                'count_frequency' => 'INTEGER DEFAULT 7 NOT NULL',
                // Approvisionnement. La CENTRALE par défaut : dans un réseau
                // de franchise, l'achat centralisé est la règle et l'achat
                // libre l'exception qu'une boutique déclare. Poser « libre »
                // d'office aurait laissé croire que chacune se débrouille,
                // alors que personne n'a rien déclaré.
                'supplier_source' => "VARCHAR(16) DEFAULT 'CENTRAL' NOT NULL",
                'supplier_ref' => "VARCHAR(64) DEFAULT '' NOT NULL",
                'supplier_name' => "VARCHAR(120) DEFAULT '' NOT NULL",
            ],
            'inv_category' => [
                // Ajoutée en même temps que celle des produits : une base
                // installée avant n'a que des rayons de composition.
                'nature' => "VARCHAR(16) DEFAULT 'COMPOSED' NOT NULL",
            ],
            'inv_consultant' => [
                // Tuiles du menu ouvertes à cette personne.
                //
                // La liste VIDE par défaut, et elle vaut « toutes ». C'est la
                // seule valeur possible : lire l'absence de réglage comme
                // « aucun droit » aurait renvoyé toute la boutique sur un menu
                // vide au premier déploiement, un matin à huit heures, sans
                // qu'un vendeur puisse y remédier.
                'tiles' => "TEXT DEFAULT '[]' NOT NULL",
            ],
            'inv_checklist_item' => [
                // Exigence de photo, réglée point par point en administration.
                'requires_photo' => 'BOOLEAN DEFAULT 0 NOT NULL',
            ],
            'inv_checklist_entry' => [
                // Le statut remplace le booléen « coché ». Les lignes déjà en
                // base gardent leur booléen : la lecture s'en sert pour
                // déduire DONE ou PENDING, sans migration de données.
                'status' => "VARCHAR(16) DEFAULT '' NOT NULL",
                'photo_path' => 'VARCHAR(255) DEFAULT NULL',
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
