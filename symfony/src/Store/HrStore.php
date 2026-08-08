<?php

declare(strict_types=1);

namespace Merisu\Inventory\Store;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Domain\Competency;
use Merisu\Inventory\Domain\JobPosition;
use Merisu\Inventory\Domain\MetricTarget;
use Merisu\Inventory\Domain\PositionLevel;
use Merisu\Inventory\Domain\ShopMetric;
use Merisu\Inventory\Domain\TargetMonth;

/**
 * Objectifs par boutique, postes RH et compétences.
 *
 * Un magasin à part de `Store`, qui tient déjà l'inventaire : ce sont deux
 * domaines qui ne se croisent nulle part, et les mêler aurait donné une classe
 * que personne ne relit.
 *
 * Tout ce qu'il écrit est au FORMAT de l'hôte — `metric_key`, `threshold_1..3`,
 * `position_id` + `level_id`, `competency_id`. Le jour où TF Buddy est
 * branché, c'est un adaptateur qui remplace ces méthodes, pas les écrans.
 */
final class HrStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    // ── Indicateurs suivis ──────────────────────────────────────────────────

    /** @return list<ShopMetric> */
    public function metrics(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM inv_shop_metric ORDER BY sort_order, metric_key',
        );

        return array_map(static fn (array $r): ShopMetric => new ShopMetric(
            (string) $r['metric_key'],
            (string) $r['label'],
            (string) $r['unit'],
            (bool) $r['lower_is_better'],
            (int) $r['sort_order'],
        ), $rows);
    }

    public function saveMetric(ShopMetric $metric): void
    {
        $data = [
            'label' => $metric->label,
            'unit' => $metric->unit,
            'lower_is_better' => $metric->lowerIsBetter ? 1 : 0,
            'sort_order' => $metric->sortOrder,
        ];

        $existe = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM inv_shop_metric WHERE metric_key = ?',
            [$metric->key],
        ) > 0;

        if ($existe) {
            $this->db->update('inv_shop_metric', $data, ['metric_key' => $metric->key]);

            return;
        }

        $this->db->insert('inv_shop_metric', $data + ['metric_key' => $metric->key]);
    }

    /**
     * Retire un indicateur ET ses seuils.
     *
     * Les seuils partent avec lui : conservés, ils décriraient un indicateur
     * qui n'existe plus, et remonteraient tels quels à l'hôte au prochain
     * envoi — sur une clé qu'il ne connaît pas.
     */
    public function deleteMetric(string $key): void
    {
        $this->db->delete('inv_shop_target', ['metric_key' => $key]);
        $this->db->delete('inv_shop_metric', ['metric_key' => $key]);
    }

    // ── Objectifs d'un mois ─────────────────────────────────────────────────

    /** @return array<string, MetricTarget> indexés par clé d'indicateur */
    public function targets(string $shopId, TargetMonth $month): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM inv_shop_target WHERE shop_id = ? AND year = ? AND month = ?',
            [$shopId, $month->year, $month->month],
        );

        $objectifs = [];

        foreach ($rows as $r) {
            $cible = MetricTarget::of(
                (string) $r['metric_key'],
                (float) $r['threshold_1'],
                (float) $r['threshold_2'],
                (float) $r['threshold_3'],
            );

            if ($cible !== null) {
                $objectifs[$cible->metricKey] = $cible;
            }
        }

        return $objectifs;
    }

    public function saveTarget(string $shopId, TargetMonth $month, MetricTarget $target, string $authorId): void
    {
        $data = [
            'threshold_1' => $target->threshold1,
            'threshold_2' => $target->threshold2,
            'threshold_3' => $target->threshold3,
            'author_id' => $authorId,
            'updated_at' => Store::now(),
        ];

        $existant = $this->db->fetchOne(
            'SELECT id FROM inv_shop_target WHERE shop_id = ? AND year = ? AND month = ? AND metric_key = ?',
            [$shopId, $month->year, $month->month, $target->metricKey],
        );

        if ($existant !== false) {
            $this->db->update('inv_shop_target', $data, ['id' => (string) $existant]);

            return;
        }

        $this->db->insert('inv_shop_target', $data + [
            'id' => Store::uuid(),
            'shop_id' => $shopId,
            'year' => $month->year,
            'month' => $month->month,
            'metric_key' => $target->metricKey,
        ]);
    }

    public function deleteTarget(string $shopId, TargetMonth $month, string $metricKey): void
    {
        $this->db->delete('inv_shop_target', [
            'shop_id' => $shopId,
            'year' => $month->year,
            'month' => $month->month,
            'metric_key' => $metricKey,
        ]);
    }

    // ── Postes RH et niveaux ────────────────────────────────────────────────

    /** @return list<JobPosition> avec leurs niveaux, ordonnés */
    public function positions(): array
    {
        $niveaux = [];

        foreach ($this->db->fetchAllAssociative(
            'SELECT * FROM inv_position_level ORDER BY level_order, name',
        ) as $r) {
            $niveaux[(string) $r['position_id']][] = new PositionLevel(
                (string) $r['id'],
                (string) $r['position_id'],
                (string) $r['name'],
                ($r['description'] ?? '') === '' ? null : (string) $r['description'],
                (int) $r['level_order'],
            );
        }

        $postes = [];

        foreach ($this->db->fetchAllAssociative(
            'SELECT * FROM inv_job_position ORDER BY sort_order, name',
        ) as $r) {
            $id = (string) $r['id'];
            $postes[] = new JobPosition(
                $id,
                (string) $r['name'],
                ($r['description'] ?? '') === '' ? null : (string) $r['description'],
                (int) $r['sort_order'],
                $niveaux[$id] ?? [],
            );
        }

        return $postes;
    }

    public function savePosition(JobPosition $position): void
    {
        $data = [
            'name' => $position->name,
            'description' => $position->description,
            'sort_order' => $position->sortOrder,
        ];

        $existe = (int) $this->db->fetchOne('SELECT COUNT(*) FROM inv_job_position WHERE id = ?', [$position->id]) > 0;

        if ($existe) {
            $this->db->update('inv_job_position', $data, ['id' => $position->id]);

            return;
        }

        $this->db->insert('inv_job_position', $data + ['id' => $position->id]);
    }

    /** Retire un poste, ses niveaux, et les affectations qui s'y rapportaient. */
    public function deletePosition(string $id): void
    {
        $this->db->delete('inv_employee_position', ['position_id' => $id]);
        $this->db->delete('inv_position_level', ['position_id' => $id]);
        $this->db->delete('inv_job_position', ['id' => $id]);
    }

    public function saveLevel(PositionLevel $level): void
    {
        $data = [
            'position_id' => $level->positionId,
            'name' => $level->name,
            'description' => $level->description,
            'level_order' => $level->order,
        ];

        $existe = (int) $this->db->fetchOne('SELECT COUNT(*) FROM inv_position_level WHERE id = ?', [$level->id]) > 0;

        if ($existe) {
            $this->db->update('inv_position_level', $data, ['id' => $level->id]);

            return;
        }

        $this->db->insert('inv_position_level', $data + ['id' => $level->id]);
    }

    /**
     * Retire un niveau, et les affectations qui le citaient.
     *
     * Sans cela, une personne resterait affectée à un niveau disparu : son
     * poste s'afficherait sans échelon, et l'envoi à l'hôte porterait un
     * `level_id` qu'il ne reconnaîtrait pas.
     */
    public function deleteLevel(string $id): void
    {
        $this->db->delete('inv_employee_position', ['level_id' => $id]);
        $this->db->delete('inv_position_level', ['id' => $id]);
    }

    // ── Compétences ─────────────────────────────────────────────────────────

    /** @return list<Competency> */
    public function competencies(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM inv_competency ORDER BY category, subcategory, sort_order, name',
        );

        return array_map(static fn (array $r): Competency => new Competency(
            (string) $r['id'],
            (string) $r['name'],
            (string) $r['category'],
            (string) $r['subcategory'],
            ($r['verification_method'] ?? '') === '' ? null : (string) $r['verification_method'],
            (int) $r['sort_order'],
        ), $rows);
    }

    public function saveCompetency(Competency $competency): void
    {
        $data = [
            'name' => $competency->name,
            'category' => $competency->category,
            'subcategory' => $competency->subcategory,
            'verification_method' => $competency->verificationMethod,
            'sort_order' => $competency->sortOrder,
        ];

        $existe = (int) $this->db->fetchOne('SELECT COUNT(*) FROM inv_competency WHERE id = ?', [$competency->id]) > 0;

        if ($existe) {
            $this->db->update('inv_competency', $data, ['id' => $competency->id]);

            return;
        }

        $this->db->insert('inv_competency', $data + ['id' => $competency->id]);
    }

    public function deleteCompetency(string $id): void
    {
        $this->db->delete('inv_employee_competency', ['competency_id' => $id]);
        $this->db->delete('inv_competency', ['id' => $id]);
    }

    // ── Affectations ────────────────────────────────────────────────────────

    /** @return array<string, array{position_id: string, level_id: string}> par consultant */
    public function employeePositions(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM inv_employee_position');
        $par = [];

        foreach ($rows as $r) {
            $par[(string) $r['consultant_id']] = [
                'position_id' => (string) $r['position_id'],
                'level_id' => (string) $r['level_id'],
            ];
        }

        return $par;
    }

    /**
     * Affecte un poste ET son niveau — ou retire l'affectation.
     *
     * Le couple est indivisible : un poste sans niveau n'est pas ce
     * qu'attend l'hôte, et ne dit rien de plus qu'une case vide.
     */
    public function assignPosition(string $consultantId, ?string $positionId, ?string $levelId): void
    {
        $this->db->delete('inv_employee_position', ['consultant_id' => $consultantId]);

        if ($positionId === null || $levelId === null || $positionId === '' || $levelId === '') {
            return;
        }

        $this->db->insert('inv_employee_position', [
            'consultant_id' => $consultantId,
            'position_id' => $positionId,
            'level_id' => $levelId,
            'assigned_at' => Store::now(),
        ]);
    }

    /** @return array<string, list<string>> compétences par consultant */
    public function employeeCompetencies(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM inv_employee_competency');
        $par = [];

        foreach ($rows as $r) {
            $par[(string) $r['consultant_id']][] = (string) $r['competency_id'];
        }

        return $par;
    }

    /** @param list<string> $competencyIds */
    public function assignCompetencies(string $consultantId, array $competencyIds): void
    {
        $this->db->delete('inv_employee_competency', ['consultant_id' => $consultantId]);

        foreach (array_unique($competencyIds) as $id) {
            if ($id === '') {
                continue;
            }

            $this->db->insert('inv_employee_competency', [
                'consultant_id' => $consultantId,
                'competency_id' => $id,
                'acquired_at' => Store::now(),
            ]);
        }
    }
}
