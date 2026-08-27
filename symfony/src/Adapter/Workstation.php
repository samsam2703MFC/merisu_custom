<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * Poste de travail (« stanowisko ») — provient du module Consultant existant.
 *
 * ── Le poste appartient à une BOUTIQUE
 *
 * C'est le chaînon qui manquait. Les comptages, les plans figés, les seuils et
 * le delta technique sont tous rattachés à un POSTE ; les ventes, elles, à une
 * BOUTIQUE. Sans lien entre les deux, un réseau de trois boutiques ne pouvait
 * comparer aucun de ces chiffres à ses ventes — le théorique se calculait par
 * boutique et le réel par poste, sans jamais se rejoindre.
 *
 * Vide = poste non rattaché. Ce n'est pas une faute : une installation d'une
 * seule boutique n'a jamais eu à le dire, et forcer un rattachement au
 * déploiement aurait inventé une appartenance que personne n'a décidée. Les
 * écrans qui filtrent par boutique le signalent plutôt que de faire disparaître
 * ses relevés.
 */
final readonly class Workstation
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $active,
        public string $shopId = '',
    ) {
    }

    public function hasShop(): bool
    {
        return trim($this->shopId) !== '';
    }
}
