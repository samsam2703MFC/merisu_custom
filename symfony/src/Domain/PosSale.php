<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qu'un produit a vendu, UN jour donné.
 *
 * La brique de toute l'analyse. La caisse sait rendre un rapport groupé par
 * mois, par jour de semaine, par heure ; on ne lui demande que le grain le
 * plus fin — produit × jour — et tout le reste s'en déduit ici.
 *
 * ── Pourquoi ne pas laisser la caisse grouper
 *
 * Parce que quatre appels rendraient quatre vérités. Le total du mois, celui
 * des semaines et celui des jours proviendraient de trois requêtes, faites à
 * trois instants, sur un jeu de commandes qui bouge — une commande rouverte,
 * un remboursement passé entre-temps — et les colonnes ne s'additionneraient
 * plus. Un seul relevé, agrégé localement, ne peut pas se contredire.
 *
 * C'est aussi ce qui alimente la moyenne des six dernières semaines dont vit
 * le stock minimum : elle a besoin du jour, pas du mois.
 */
final readonly class PosSale
{
    public function __construct(
        /** Date de vente, au format Y-m-d. */
        public string $date,
        /** Référence de l'article dans la caisse — le lien avec la fiche produit. */
        public string $externalId,
        /** Le libellé de la caisse, gardé pour les articles non rattachés. */
        public string $name,
        public float $quantity,
        public float $revenue,
        /**
         * Le code de la boutique d'où vient la vente.
         *
         * Vide pour un réseau d'une seule boutique, et pour les relevés faits
         * avant que le réseau n'existe : c'est une valeur légitime, pas un
         * trou. La confondre avec « toutes boutiques » aurait fait disparaître
         * l'historique du jour où la deuxième a ouvert.
         */
        public string $shopCode = '',
    ) {
    }

    /**
     * Une ligne de rapport GoPOS.
     *
     * L'horodatage arrive en MILLISECONDES depuis l'époque, sous forme de
     * chaîne — « 1786147200000 ». Le lire en secondes aurait daté toutes les
     * ventes de l'an 58000 et vidé chaque intervalle sans erreur visible.
     *
     * @param array<string, mixed> $row      le sous-rapport d'une journée
     * @param string               $timezone fuseau de la boutique
     */
    public static function fromReport(
        array $row,
        string $externalId,
        string $name,
        string $timezone,
        string $shopCode = '',
    ): ?self {
        $valeur = $row['group_by_value']['name'] ?? null;

        if (!is_numeric($valeur)) {
            return null;
        }

        $ventes = $row['aggregate']['sales'] ?? [];
        $quantite = is_numeric($ventes['product_quantity'] ?? null) ? (float) $ventes['product_quantity'] : 0.0;

        // La date est celle de la BOUTIQUE, pas d'UTC. Une vente de 23 h à
        // Varsovie tombe la veille en UTC : lue ainsi, la journée du lundi
        // perdrait sa dernière heure au profit du dimanche, et la moyenne des
        // lundis s'en trouverait faussée toute l'année.
        try {
            $instant = (new \DateTimeImmutable('@' . intdiv((int) $valeur, 1000)))
                ->setTimezone(new \DateTimeZone($timezone));
        } catch (\Exception) {
            return null;
        }

        return new self(
            $instant->format('Y-m-d'),
            $externalId,
            trim($name),
            $quantite,
            self::montant($ventes['total_money'] ?? null),
            $shopCode,
        );
    }

    private static function montant(mixed $money): float
    {
        return is_array($money) && is_numeric($money['amount'] ?? null) ? (float) $money['amount'] : 0.0;
    }
}
