<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une remontée en attente vers le système hôte — patron « boîte d'envoi ».
 *
 * ── Pourquoi une file, et non un appel direct
 *
 * Le comptage se valide au comptoir, sur un appareil dont le réseau tombe
 * régulièrement — c'est toute la raison d'être du mode hors-ligne de cette
 * PWA. Appeler TF Buddy pendant la requête de validation reviendrait à faire
 * dépendre un geste local de la disponibilité d'un service distant : une
 * panne chez l'hôte, et le vendeur ne peut plus clôturer sa journée.
 *
 * La validation écrit donc dans cette file, en base, dans la même transaction
 * que le comptage. L'envoi se fait ensuite, hors requête, et peut échouer
 * autant de fois qu'il le faut sans que personne au comptoir s'en aperçoive.
 *
 * ── Pourquoi elle ne s'efface jamais
 *
 * Une ligne envoyée reste en base, horodatée : c'est la preuve qu'un comptage
 * réel a été transmis, et §5 exige la traçabilité de chaque saisie. Une ligne
 * abandonnée reste aussi, avec sa charge utile et sa dernière erreur — la
 * jeter ferait disparaître un comptage que personne n'a pu vérifier.
 */
final readonly class OutboxEntry
{
    /**
     * Au-delà, on cesse de réessayer.
     *
     * Huit tentatives avec le recul ci-dessous couvrent un peu plus de deux
     * heures : de quoi traverser un redémarrage de l'hôte sans intervention.
     * Réessayer indéfiniment masquerait une panne durable derrière une file
     * qui grossit en silence.
     */
    public const MAX_ATTEMPTS = 8;

    public function __construct(
        public int $id,
        /** Ce qui est remonté — voir SyncKind. */
        public SyncKind $kind,
        /** @var array<string,mixed> Charge utile, telle qu'elle sera envoyée. */
        public array $payload,
        public SyncStatus $status = SyncStatus::Pending,
        public int $attempts = 0,
        public ?string $lastError = null,
        public ?string $createdAt = null,
        public ?string $sentAt = null,
        /** Horodatage de la prochaine tentative, en UTC « Y-m-d H:i:s ». */
        public ?string $nextAttemptAt = null,
    ) {
    }

    /**
     * Recul exponentiel avant la n-ième tentative, en secondes.
     *
     * 1 min, 2, 4, 8… plafonné à une heure. Le plafond compte autant que la
     * croissance : sans lui, la sixième tentative attendrait une demi-journée
     * et une panne de dix minutes coûterait une journée de retard.
     */
    public static function backoffSeconds(int $attempts): int
    {
        return min(3600, 60 * (2 ** max(0, $attempts - 1)));
    }

    /**
     * Est-elle à envoyer maintenant ?
     *
     * `$now` est passé plutôt que lu : une décision qui dépend de l'horloge
     * ne se teste pas.
     */
    public function isDue(string $now): bool
    {
        return $this->status === SyncStatus::Pending
            && ($this->nextAttemptAt === null || $this->nextAttemptAt <= $now);
    }

    /** A-t-elle épuisé ses tentatives ? */
    public function isExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }
}
