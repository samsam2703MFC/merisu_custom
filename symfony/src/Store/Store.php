<?php

declare(strict_types=1);

namespace Merisu\Inventory\Store;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Domain\AuditEntry;
use Merisu\Inventory\Domain\ChecklistEntry;
use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ChecklistSection;
use Merisu\Inventory\Domain\CountMode;
use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Domain\CountPhoto;
use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\GeneralSettings;
use Merisu\Inventory\Domain\InventoryCount;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\MaterialMovement;
use Merisu\Inventory\Domain\ParMatrixEntry;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductionPlanRow;
use Merisu\Inventory\Domain\ProductionPlanStatus;
use Merisu\Inventory\Domain\RoundingMode;

/**
 * Accès aux données du module.
 *
 * Doctrine DBAL plutôt que l'ORM : le schéma est simple, le SQL reste lisible
 * pour l'équipe qui exploitera la base, et rien n'est masqué par du mapping.
 * Aucun SQL ne remonte au-dessus de cette couche.
 */
final class Store
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    // ── Paramètres ──────────────────────────────────────────────────────────

    public function settings(): GeneralSettings
    {
        $row = $this->db->fetchAssociative('SELECT * FROM inv_settings WHERE id = 1');

        if ($row === false) {
            return GeneralSettings::defaults();
        }

        return new GeneralSettings(
            (string) $row['opening_time'],
            (string) $row['closing_time'],
            (string) $row['timezone'],
            Locale::tryFrom((string) $row['default_locale']) ?? Locale::Fr,
            (bool) $row['photo_required'],
            (bool) $row['photo_per_product'],
            (float) $row['delta_tolerance'],
            // Colonne ajoutée après coup : une base installée avant ne l'a pas
            // encore au moment où `settings()` est appelé la première fois.
            (int) ($row['monthly_tiramisu_target'] ?? 0),
        );
    }

    public function saveSettings(GeneralSettings $settings): void
    {
        $this->db->update('inv_settings', [
            'opening_time' => $settings->openingTime,
            'closing_time' => $settings->closingTime,
            'timezone' => $settings->timezone,
            'default_locale' => $settings->defaultLocale->value,
            'photo_required' => $settings->photoRequired ? 1 : 0,
            'photo_per_product' => $settings->photoPerProduct ? 1 : 0,
            'delta_tolerance' => $settings->deltaTolerance,
            'monthly_tiramisu_target' => $settings->monthlyTiramisuTarget,
        ], ['id' => 1]);
    }

    // ── Produits ────────────────────────────────────────────────────────────

    /** @return list<Product> */
    public function products(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM inv_product' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, code';

        return array_map($this->hydrateProduct(...), $this->db->fetchAllAssociative($sql));
    }

    public function product(string $id): ?Product
    {
        $row = $this->db->fetchAssociative('SELECT * FROM inv_product WHERE id = ?', [$id]);

        return $row === false ? null : $this->hydrateProduct($row);
    }

    public function saveProduct(Product $product): void
    {
        $data = [
            'code' => $product->code,
            'name' => json_encode($product->name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'unit' => $product->unit,
            'active' => $product->active ? 1 : 0,
            'waste_factor' => $product->wasteFactor,
            'rounding_step' => $product->roundingStep,
            'rounding_mode' => $product->roundingMode->value,
            'recipe_ref' => $product->recipeRef,
            'sort_order' => $product->sortOrder,
            'count_mode' => $product->countMode->value,
            'category' => $product->category,
        ];

        $exists = (int) $this->db->fetchOne('SELECT COUNT(*) FROM inv_product WHERE id = ?', [$product->id]) > 0;

        if ($exists) {
            $this->db->update('inv_product', $data, ['id' => $product->id]);
        } else {
            $this->db->insert('inv_product', $data + ['id' => $product->id]);
        }
    }

    // ── Matrice des seuils ──────────────────────────────────────────────────

    /** @return list<ParMatrixEntry> */
    public function parMatrix(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM inv_par_matrix');

        return array_map(static fn (array $r): ParMatrixEntry => new ParMatrixEntry(
            (string) $r['id'],
            (string) $r['product_id'],
            DayOfWeek::from((string) $r['day_of_week']),
            (float) $r['required_pieces'],
            ($r['workstation_id'] ?? '') === '' ? null : (string) $r['workstation_id'],
        ), $rows);
    }

    /**
     * Insère ou met à jour un seuil. `$requiredPieces === null` SUPPRIME le seuil
     * — à ne pas confondre avec un seuil volontairement fixé à 0.
     */
    public function saveParEntry(
        string $productId,
        DayOfWeek $day,
        ?float $requiredPieces,
        ?string $workstationId = null,
    ): void {
        $criteria = [
            'product_id' => $productId,
            'day_of_week' => $day->value,
            'workstation_id' => $workstationId ?? '',
        ];

        if ($requiredPieces === null) {
            $this->db->delete('inv_par_matrix', $criteria);

            return;
        }

        $affected = $this->db->update('inv_par_matrix', ['required_pieces' => $requiredPieces], $criteria);

        if ($affected === 0) {
            $this->db->insert('inv_par_matrix', $criteria + [
                'id' => self::uuid(),
                'required_pieces' => $requiredPieces,
            ]);
        }
    }

    // ── Comptages ───────────────────────────────────────────────────────────

    /**
     * @param array{date?: string, from?: string, to?: string, workstationId?: string,
     *              moment?: CountMoment, productId?: string} $query
     *
     * @return list<InventoryCount>
     */
    public function counts(array $query): array
    {
        $where = [];
        $params = [];

        if (isset($query['date'])) {
            $where[] = 'business_date = ?';
            $params[] = $query['date'];
        }
        if (isset($query['from'])) {
            $where[] = 'business_date >= ?';
            $params[] = $query['from'];
        }
        if (isset($query['to'])) {
            $where[] = 'business_date <= ?';
            $params[] = $query['to'];
        }
        if (isset($query['workstationId'])) {
            $where[] = 'workstation_id = ?';
            $params[] = $query['workstationId'];
        }
        if (isset($query['moment'])) {
            $where[] = 'moment = ?';
            $params[] = $query['moment']->value;
        }
        if (isset($query['productId'])) {
            $where[] = 'product_id = ?';
            $params[] = $query['productId'];
        }

        $sql = 'SELECT * FROM inv_count'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY business_date, product_id';

        $rows = $this->db->fetchAllAssociative($sql, $params);
        if ($rows === []) {
            return [];
        }

        $photos = $this->photosFor(array_column($rows, 'id'));

        return array_map(
            fn (array $r): InventoryCount => $this->hydrateCount($r, $photos[$r['id']] ?? []),
            $rows,
        );
    }

    public function count(string $id): ?InventoryCount
    {
        $row = $this->db->fetchAssociative('SELECT * FROM inv_count WHERE id = ?', [$id]);

        return $row === false ? null : $this->hydrateCount($row, $this->photosFor([$id])[$id] ?? []);
    }

    /**
     * Insère ou met à jour un comptage.
     * Clé métier : date + poste + produit + moment — c'est ce qui rend l'écriture
     * idempotente, donc rejouable par la file d'attente hors-ligne.
     */
    public function saveCount(
        string $date,
        string $workstationId,
        string $consultantId,
        string $productId,
        CountMoment $moment,
        float $qty,
        ?float $producedQty = null,
    ): InventoryCount {
        $now = self::now();
        $criteria = [
            'business_date' => $date,
            'workstation_id' => $workstationId,
            'product_id' => $productId,
            'moment' => $moment->value,
        ];

        $existingId = $this->db->fetchOne(
            'SELECT id FROM inv_count WHERE business_date = ? AND workstation_id = ? AND product_id = ? AND moment = ?',
            array_values($criteria),
        );

        if ($existingId !== false) {
            $data = ['qty' => $qty, 'consultant_id' => $consultantId, 'updated_at' => $now];
            if ($producedQty !== null) {
                $data['produced_qty'] = $producedQty;
            }
            $this->db->update('inv_count', $data, ['id' => $existingId]);

            return $this->count((string) $existingId);
        }

        $id = self::uuid();
        $this->db->insert('inv_count', $criteria + [
            'id' => $id,
            'consultant_id' => $consultantId,
            'qty' => $qty,
            'produced_qty' => $producedQty,
            'validated' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->count($id);
    }

    /** Marque (dé)validés tous les comptages d'un couple (date, poste, moment). */
    public function setValidated(
        string $date,
        string $workstationId,
        CountMoment $moment,
        bool $validated,
        string $actorId,
    ): int {
        return (int) $this->db->update(
            'inv_count',
            [
                'validated' => $validated ? 1 : 0,
                'validated_at' => $validated ? self::now() : null,
                'validated_by' => $validated ? $actorId : null,
                'updated_at' => self::now(),
            ],
            ['business_date' => $date, 'workstation_id' => $workstationId, 'moment' => $moment->value],
        );
    }

    public function addPhoto(string $countId, string $url): void
    {
        $this->db->insert('inv_count_photo', [
            'id' => self::uuid(),
            'inventory_count_id' => $countId,
            'url' => $url,
            'taken_at' => self::now(),
        ]);
    }

    // ── Plans de production ─────────────────────────────────────────────────

    /** @return list<ProductionPlanRow> */
    public function plan(string $forDate, string $workstationId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM inv_production_plan WHERE for_date = ? AND workstation_id = ? ORDER BY product_id',
            [$forDate, $workstationId],
        );

        return array_map($this->hydratePlan(...), $rows);
    }

    /**
     * Plans figés sur une période, tous postes confondus si `$workstationId` est nul.
     *
     * Utilisé par le delta technique : la date visée d'un plan est le LENDEMAIN du
     * comptage, les retrouver via les comptages de la période donnerait un
     * ensemble faux.
     *
     * @return list<ProductionPlanRow>
     */
    public function plansBetween(string $from, string $to, ?string $workstationId = null): array
    {
        $sql = 'SELECT * FROM inv_production_plan WHERE for_date >= ? AND for_date <= ?';
        $params = [$from, $to];

        if ($workstationId !== null) {
            $sql .= ' AND workstation_id = ?';
            $params[] = $workstationId;
        }

        return array_map($this->hydratePlan(...), $this->db->fetchAllAssociative($sql . ' ORDER BY for_date, product_id', $params));
    }

    /**
     * Remplace le plan d'un couple (date visée, poste).
     *
     * @param list<array{productId: string, requiredPieces: float, closingStock: float,
     *                   qtyToProduce: float, missingThreshold: bool}> $lines
     */
    public function replacePlan(string $forDate, string $workstationId, array $lines): void
    {
        $this->db->transactional(function () use ($forDate, $workstationId, $lines): void {
            $this->db->delete('inv_production_plan', ['for_date' => $forDate, 'workstation_id' => $workstationId]);

            $computedAt = self::now();
            foreach ($lines as $line) {
                $this->db->insert('inv_production_plan', [
                    'id' => self::uuid(),
                    'for_date' => $forDate,
                    'product_id' => $line['productId'],
                    'workstation_id' => $workstationId,
                    'required_pieces' => $line['requiredPieces'],
                    'closing_stock' => $line['closingStock'],
                    'qty_to_produce' => $line['qtyToProduce'],
                    'missing_threshold' => $line['missingThreshold'] ? 1 : 0,
                    'computed_at' => $computedAt,
                    'status' => ProductionPlanStatus::Frozen->value,
                ]);
            }
        });
    }

    // ── Consommation réelle de matières ─────────────────────────────────────

    /** @return list<MaterialMovement> */
    public function materialMovements(string $from, string $to, ?string $workstationId = null): array
    {
        $sql = 'SELECT * FROM inv_material_movement WHERE business_date >= ? AND business_date <= ?';
        $params = [$from, $to];

        if ($workstationId !== null) {
            $sql .= ' AND workstation_id = ?';
            $params[] = $workstationId;
        }

        $rows = $this->db->fetchAllAssociative($sql . ' ORDER BY business_date, material_id', $params);

        return array_map(static fn (array $r): MaterialMovement => new MaterialMovement(
            (string) $r['id'],
            (string) $r['business_date'],
            (string) $r['material_id'],
            (float) $r['real_qty'],
            $r['workstation_id'] === null ? null : (string) $r['workstation_id'],
            (string) $r['recorded_by'],
            (string) $r['recorded_at'],
            $r['note'] === null ? null : (string) $r['note'],
        ), $rows);
    }

    public function addMaterialMovement(
        string $date,
        string $materialId,
        float $realQty,
        string $recordedBy,
        ?string $workstationId = null,
        ?string $note = null,
    ): void {
        $this->db->insert('inv_material_movement', [
            'id' => self::uuid(),
            'business_date' => $date,
            'material_id' => $materialId,
            'real_qty' => $realQty,
            'workstation_id' => $workstationId,
            'recorded_by' => $recordedBy,
            'recorded_at' => self::now(),
            'note' => $note,
        ]);
    }

    // ── Check-list ──────────────────────────────────────────────────────────

    /** @return list<ChecklistItem> */
    public function checklistItems(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM inv_checklist_item'
            . ($activeOnly ? ' WHERE active = 1' : '')
            . ' ORDER BY section, sort_order, id';

        return array_map($this->hydrateChecklistItem(...), $this->db->fetchAllAssociative($sql));
    }

    public function checklistItem(string $id): ?ChecklistItem
    {
        $row = $this->db->fetchAssociative('SELECT * FROM inv_checklist_item WHERE id = ?', [$id]);

        return $row === false ? null : $this->hydrateChecklistItem($row);
    }

    public function saveChecklistItem(ChecklistItem $item): void
    {
        $data = [
            'section' => $item->section->value,
            'label' => json_encode($item->label, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'sort_order' => $item->sortOrder,
            'active' => $item->active ? 1 : 0,
            'required' => $item->required ? 1 : 0,
        ];

        $exists = (int) $this->db->fetchOne('SELECT COUNT(*) FROM inv_checklist_item WHERE id = ?', [$item->id]) > 0;

        if ($exists) {
            $this->db->update('inv_checklist_item', $data, ['id' => $item->id]);
        } else {
            $this->db->insert('inv_checklist_item', $data + ['id' => $item->id]);
        }
    }

    public function deleteChecklistItem(string $id): void
    {
        // Les cochages passés sont conservés : ils documentent ce qui a été
        // fait, même si le point n'est plus au programme aujourd'hui.
        $this->db->delete('inv_checklist_item', ['id' => $id]);
    }

    /** @return array<string,ChecklistEntry> Indexé par identifiant de point. */
    public function checklistEntries(string $businessDate, string $workstationId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM inv_checklist_entry WHERE business_date = ? AND workstation_id = ?',
            [$businessDate, $workstationId],
        );

        $entries = [];

        foreach ($rows as $row) {
            $entries[(string) $row['item_id']] = new ChecklistEntry(
                (string) $row['id'],
                (string) $row['business_date'],
                (string) $row['workstation_id'],
                (string) $row['item_id'],
                (bool) $row['checked'],
                (string) $row['consultant_id'],
                (string) $row['checked_at'],
                $row['note'] === null || $row['note'] === '' ? null : (string) $row['note'],
            );
        }

        return $entries;
    }

    /**
     * Enregistre l'état d'un point.
     *
     * L'auteur et l'horodatage sont RÉÉCRITS à chaque changement : c'est la
     * dernière personne à avoir statué qui engage sa responsabilité, pas la
     * première à avoir touché la case.
     */
    public function setChecklistEntry(
        string $businessDate,
        string $workstationId,
        string $itemId,
        bool $checked,
        string $consultantId,
        ?string $note = null,
    ): void {
        $existing = $this->db->fetchOne(
            'SELECT id FROM inv_checklist_entry WHERE business_date = ? AND workstation_id = ? AND item_id = ?',
            [$businessDate, $workstationId, $itemId],
        );

        $data = [
            'checked' => $checked ? 1 : 0,
            'consultant_id' => $consultantId,
            'checked_at' => self::now(),
            'note' => $note,
        ];

        if ($existing !== false) {
            $this->db->update('inv_checklist_entry', $data, ['id' => (string) $existing]);

            return;
        }

        $this->db->insert('inv_checklist_entry', $data + [
            'id' => self::uuid(),
            'business_date' => $businessDate,
            'workstation_id' => $workstationId,
            'item_id' => $itemId,
        ]);
    }

    // ── Arrêt de production ─────────────────────────────────────────────────

    // ── Audit ───────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $details */
    public function audit(
        string $actorId,
        string $actorRole,
        string $action,
        ?string $workstationId = null,
        ?string $businessDate = null,
        array $details = [],
    ): void {
        $this->db->insert('inv_audit', [
            'id' => self::uuid(),
            'at' => self::now(),
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'action' => $action,
            'workstation_id' => $workstationId,
            'business_date' => $businessDate,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<AuditEntry> */
    public function auditEntries(string $from, string $to, ?string $workstationId = null, int $limit = 300): array
    {
        $sql = 'SELECT * FROM inv_audit WHERE (business_date IS NULL OR (business_date >= ? AND business_date <= ?))';
        $params = [$from, $to];

        if ($workstationId !== null) {
            $sql .= ' AND workstation_id = ?';
            $params[] = $workstationId;
        }

        $rows = $this->db->fetchAllAssociative($sql . ' ORDER BY at DESC LIMIT ' . max(1, $limit), $params);

        return array_map(static fn (array $r): AuditEntry => new AuditEntry(
            (string) $r['id'],
            (string) $r['at'],
            (string) $r['actor_id'],
            (string) $r['actor_role'],
            (string) $r['action'],
            $r['workstation_id'] === null ? null : (string) $r['workstation_id'],
            $r['business_date'] === null ? null : (string) $r['business_date'],
            json_decode((string) $r['details'], true) ?: [],
        ), $rows);
    }

    // ── Hydratation ─────────────────────────────────────────────────────────

    /** @param array<string,mixed> $row */
    private function hydrateChecklistItem(array $row): ChecklistItem
    {
        return new ChecklistItem(
            (string) $row['id'],
            ChecklistSection::tryFromLoose((string) $row['section']) ?? ChecklistSection::Opening,
            json_decode((string) $row['label'], true) ?: [],
            (int) $row['sort_order'],
            (bool) $row['active'],
            (bool) $row['required'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateProduct(array $row): Product
    {
        return new Product(
            (string) $row['id'],
            (string) $row['code'],
            json_decode((string) $row['name'], true) ?: [],
            (string) $row['unit'],
            (bool) $row['active'],
            (float) $row['waste_factor'],
            (float) $row['rounding_step'],
            RoundingMode::from((string) $row['rounding_mode']),
            $row['recipe_ref'] === null ? null : (string) $row['recipe_ref'],
            (int) $row['sort_order'],
            // Repli sur l'unité : une base installée avant l'ajout du mode
            // n'a que des produits comptés à la pièce.
            CountMode::tryFromLoose($row['count_mode'] ?? null) ?? CountMode::Pieces,
            trim((string) ($row['category'] ?? '')),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param list<CountPhoto>    $photos
     */
    private function hydrateCount(array $row, array $photos): InventoryCount
    {
        return new InventoryCount(
            (string) $row['id'],
            (string) $row['business_date'],
            (string) $row['workstation_id'],
            (string) $row['consultant_id'],
            (string) $row['product_id'],
            CountMoment::from((string) $row['moment']),
            (float) $row['qty'],
            $row['produced_qty'] === null ? null : (float) $row['produced_qty'],
            (bool) $row['validated'],
            $row['validated_at'] === null ? null : (string) $row['validated_at'],
            $row['validated_by'] === null ? null : (string) $row['validated_by'],
            $photos,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydratePlan(array $row): ProductionPlanRow
    {
        return new ProductionPlanRow(
            (string) $row['id'],
            (string) $row['for_date'],
            (string) $row['product_id'],
            (string) $row['workstation_id'],
            (float) $row['required_pieces'],
            (float) $row['closing_stock'],
            (float) $row['qty_to_produce'],
            (string) $row['computed_at'],
            ProductionPlanStatus::from((string) $row['status']),
            (bool) $row['missing_threshold'],
        );
    }

    /**
     * Photos de plusieurs comptages en une requête (évite le N+1 sur la feuille
     * de saisie, qui affiche 8 produits d'un coup).
     *
     * @param list<mixed> $countIds
     *
     * @return array<string, list<CountPhoto>>
     */
    private function photosFor(array $countIds): array
    {
        if ($countIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($countIds), '?'));
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM inv_count_photo WHERE inventory_count_id IN ($placeholders) ORDER BY taken_at",
            array_map(strval(...), $countIds),
        );

        $byCount = [];
        foreach ($rows as $row) {
            $byCount[(string) $row['inventory_count_id']][] = new CountPhoto(
                (string) $row['id'],
                (string) $row['inventory_count_id'],
                (string) $row['url'],
                (string) $row['taken_at'],
            );
        }

        return $byCount;
    }

    public static function uuid(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
    }
}
