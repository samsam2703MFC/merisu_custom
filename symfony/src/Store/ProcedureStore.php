<?php

declare(strict_types=1);

namespace Merisu\Inventory\Store;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Domain\Procedure;

/**
 * Le manuel opératoire en base : lecture, écriture, suppression.
 *
 * Séparé de `Store` comme `ShopStore` l'est : `Store` porte déjà mille neuf
 * cents lignes, et le manuel ne partage rien avec les comptages — ni table, ni
 * clé, ni règle. L'y ajouter n'aurait fait que grossir un fichier que personne
 * ne relit plus.
 */
final class ProcedureStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<Procedure> Dans l'ordre d'affichage. */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM inv_procedure' . ($activeOnly ? ' WHERE active = 1' : '')
            . ' ORDER BY topic, sort_order, id';

        return array_map($this->hydrate(...), $this->db->fetchAllAssociative($sql));
    }

    public function find(string $id): ?Procedure
    {
        $row = $this->db->fetchAssociative('SELECT * FROM inv_procedure WHERE id = ?', [$id]);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Un emplacement libre, fabriqué et non demandé.
     *
     * Même règle que pour les boutiques : un identifiant saisi deux fois
     * écraserait une procédure existante, et personne ne le verrait avant
     * d'aller la chercher en pleine panne.
     *
     * @return array{id: string, sortOrder: int}
     */
    public function nextSlot(): array
    {
        $pris = array_flip($this->db->fetchFirstColumn('SELECT id FROM inv_procedure'));

        $rang = \count($pris) + 1;
        while (isset($pris['proc-' . $rang])) {
            ++$rang;
        }

        $dernier = (int) $this->db->fetchOne('SELECT MAX(sort_order) FROM inv_procedure');

        return ['id' => 'proc-' . $rang, 'sortOrder' => $dernier + 1];
    }

    public function save(Procedure $procedure): void
    {
        $data = [
            'title' => self::encode($procedure->title),
            'problem' => self::encode($procedure->problem),
            'solution' => self::encode($procedure->solution),
            'photos' => self::encode(array_values($procedure->photos)),
            'topic' => $procedure->topic,
            'sort_order' => $procedure->sortOrder,
            'active' => $procedure->active ? 1 : 0,
        ];

        $existe = $this->db->fetchOne('SELECT COUNT(*) FROM inv_procedure WHERE id = ?', [$procedure->id]);

        if ((int) $existe === 0) {
            $this->db->insert('inv_procedure', $data + ['id' => $procedure->id]);

            return;
        }

        $this->db->update('inv_procedure', $data, ['id' => $procedure->id]);
    }

    public function delete(string $id): void
    {
        $this->db->delete('inv_procedure', ['id' => $id]);
    }

    /** Les rayons déjà employés, pour les proposer plutôt que les faire retaper. */
    public function topics(): array
    {
        $rayons = $this->db->fetchFirstColumn(
            "SELECT DISTINCT topic FROM inv_procedure WHERE topic <> '' ORDER BY topic",
        );

        return array_map(strval(...), $rayons);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Procedure
    {
        return new Procedure(
            (string) $row['id'],
            self::decode($row['title'] ?? null),
            self::decode($row['problem'] ?? null),
            self::decode($row['solution'] ?? null),
            array_values(self::decode($row['photos'] ?? null)),
            (string) ($row['topic'] ?? ''),
            (int) ($row['sort_order'] ?? 0),
            (bool) ($row['active'] ?? true),
        );
    }

    /** @param array<mixed> $valeur */
    private static function encode(array $valeur): string
    {
        return json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Un JSON illisible rend un tableau VIDE, jamais une erreur.
     *
     * Une procédure abîmée doit s'afficher amputée et se corriger à l'écran ;
     * faire tomber la page entière retirerait aussi les quarante autres, en
     * pleine panne, ce qui est exactement le moment où le manuel sert.
     *
     * @return array<mixed>
     */
    private static function decode(mixed $brut): array
    {
        if (!\is_string($brut) || trim($brut) === '') {
            return [];
        }

        try {
            $valeur = json_decode($brut, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($valeur) ? $valeur : [];
    }
}
