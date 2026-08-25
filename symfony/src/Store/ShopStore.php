<?php

declare(strict_types=1);

namespace Merisu\Inventory\Store;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Domain\Shop;
use Merisu\Inventory\Service\SecretBox;

/**
 * Les boutiques du réseau, et TOUT ce qu'une boutique implique.
 *
 * ── Pourquoi ce magasin est à part
 *
 * Une fiche de boutique porte un secret de caisse. Le chiffrer demande la
 * `SecretBox`, que `Store` n'a pas et n'a pas à avoir : ce serait la première
 * clé de chiffrement dans une classe qui ne fait que du SQL. Les identifiants
 * de caisse et la clé météo ont déjà chacun leur magasin, pour la même raison.
 *
 * ── Le secret ne se relit pas, donc il ne s'efface pas par distraction
 *
 * Laissé vide à l'enregistrement, il est CONSERVÉ. Sans cette règle, corriger
 * une adresse aurait effacé un secret que personne ne peut retaper.
 */
final readonly class ShopStore
{
    public function __construct(
        private Connection $db,
        private SecretBox $box,
    ) {
    }

    /** @return list<Shop> */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM inv_shop' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, code';

        return array_map($this->hydrate(...), $this->db->fetchAllAssociative($sql));
    }

    public function find(string $id): ?Shop
    {
        $row = $this->db->fetchAssociative('SELECT * FROM inv_shop WHERE id = ?', [$id]);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Réserve un emplacement libre.
     *
     * L'identifiant et le code se fabriquent, ils ne se demandent pas : un
     * administrateur pressé aurait saisi deux fois le même code, et deux
     * boutiques auraient partagé leurs comptages.
     *
     * @return array{id: string, code: string, sortOrder: int}
     */
    public function nextSlot(): array
    {
        $pris = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, code FROM inv_shop') as $r) {
            $pris[(string) $r['id']] = true;
            $pris[(string) $r['code']] = true;
        }

        $rang = 1;
        while (isset($pris['shop-' . $rang]) || isset($pris['BOUTIQUE_' . $rang])) {
            ++$rang;
        }

        $dernier = (int) $this->db->fetchOne('SELECT MAX(sort_order) FROM inv_shop');

        return ['id' => 'shop-' . $rang, 'code' => 'BOUTIQUE_' . $rang, 'sortOrder' => $dernier + 1];
    }

    /**
     * Enregistre la fiche entière.
     *
     * @throws \RuntimeException si un secret est fourni sans chiffrement possible
     */
    public function save(Shop $shop): void
    {
        $ancien = $this->db->fetchOne('SELECT pos_client_secret FROM inv_shop WHERE id = ?', [$shop->id]);
        $chiffre = $ancien === false ? null : (string) $ancien;

        if (trim($shop->posClientSecret) !== '') {
            $chiffre = $this->box->encrypt(trim($shop->posClientSecret))
                ?? throw new \RuntimeException('SECRET_BOX_UNAVAILABLE');
        }

        $data = [
            'code' => $shop->code,
            'name' => $shop->name,
            'address' => $shop->address,
            'postal_code' => $shop->postalCode,
            'city' => $shop->city,
            'latitude' => $shop->latitude,
            'longitude' => $shop->longitude,
            'pos_organization_id' => $shop->posOrganizationId,
            'pos_client_id' => $shop->posClientId,
            'pos_client_secret' => $chiffre,
            'opening_time' => $shop->openingTime,
            'closing_time' => $shop->closingTime,
            'timezone' => $shop->timezone,
            'photo_required' => $shop->photoRequired ? 1 : 0,
            'photo_per_product' => $shop->photoPerProduct ? 1 : 0,
            'delta_tolerance' => $shop->deltaTolerance,
            'monthly_target' => $shop->monthlyTarget,
            'active' => $shop->active ? 1 : 0,
            'sort_order' => $shop->sortOrder,
            'logo_path' => $shop->logoPath,
        ];

        if ($ancien === false) {
            $this->db->insert('inv_shop', $data + ['id' => $shop->id]);

            return;
        }

        $this->db->update('inv_shop', $data, ['id' => $shop->id]);
    }

    /**
     * Retire la FICHE, pas ce que la boutique a produit.
     *
     * Comptages, plans et historique gardent son code. Effacer en cascade
     * aurait fait disparaître la preuve qu'un inventaire réel a eu lieu — et
     * c'est précisément ce que l'audit doit pouvoir montrer trois ans plus
     * tard.
     */
    public function delete(string $id): void
    {
        $this->db->delete('inv_shop', ['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Shop
    {
        return new Shop(
            (string) $row['id'],
            (string) $row['code'],
            (string) ($row['name'] ?? ''),
            (string) ($row['address'] ?? ''),
            (string) ($row['postal_code'] ?? ''),
            (string) ($row['city'] ?? ''),
            (float) ($row['latitude'] ?? 0),
            (float) ($row['longitude'] ?? 0),
            (string) ($row['pos_organization_id'] ?? ''),
            (string) ($row['pos_client_id'] ?? ''),
            // Un secret illisible — `APP_SECRET` changé depuis — rend une
            // chaîne vide plutôt qu'un charabia : la caisse se déclare alors
            // non configurée au lieu d'envoyer des octets au hasard.
            $this->box->decrypt(
                ($row['pos_client_secret'] ?? null) === null ? null : (string) $row['pos_client_secret'],
            ) ?? '',
            (string) ($row['opening_time'] ?? '08:00'),
            (string) ($row['closing_time'] ?? '22:00'),
            (string) ($row['timezone'] ?? 'Europe/Warsaw'),
            (bool) ($row['photo_required'] ?? false),
            (bool) ($row['photo_per_product'] ?? false),
            (float) ($row['delta_tolerance'] ?? 0.05),
            (int) ($row['monthly_target'] ?? 0),
            (bool) ($row['active'] ?? true),
            (int) ($row['sort_order'] ?? 0),
            (string) ($row['logo_path'] ?? ''),
        );
    }
}
