<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\OutboxEntry;

/**
 * Implémentation d'attente : elle n'envoie rien, et le dit.
 *
 * Branchée tant que le contrat des endpoints d'inventaire TF Buddy n'est pas
 * connu. Elle n'est PAS un trou noir : `isConfigured()` renvoie false, la file
 * n'est donc jamais vidée contre elle et aucune tentative n'est consommée.
 * Les comptages s'accumulent en base, intacts, et partiront tous à la première
 * exécution de `merisu:synchroniser` une fois la vraie implémentation en place.
 *
 * C'est le choix inverse d'un adaptateur qui accepterait tout en silence :
 * celui-là aurait marqué les lignes « envoyées » sans que rien ne parte, et
 * personne ne l'aurait su avant le premier écart d'inventaire.
 */
final class NullInventorySync implements InventorySyncInterface
{
    public function send(OutboxEntry $entry): void
    {
        throw new SyncUnavailable('SYNC_NOT_CONFIGURED');
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
