<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/** Paramètres généraux, tous modifiables en administration (§3.3.3). */
final readonly class GeneralSettings
{
    public function __construct(
        /** Heure du comptage d'ouverture, format `HH:MM`. */
        public string $openingTime,
        /** Heure du comptage de clôture, format `HH:MM`. */
        public string $closingTime,
        /** Fuseau IANA, ex. `Europe/Warsaw`. */
        public string $timezone,
        public Locale $defaultLocale,
        public bool $photoRequired,
        /** true = une photo par produit ; false = une photo globale suffit. */
        public bool $photoPerProduct,
        /** Tolérance du delta technique : 0.05 = 5 %. */
        public float $deltaTolerance,
        /**
         * Objectif de tiramisu vendus dans le mois, pour la jauge du réseau.
         *
         * 0 = aucun objectif fixé : la jauge ne s'affiche pas plutôt que de
         * montrer une barre pleine ou une division par zéro.
         */
        public int $monthlyTiramisuTarget = 0,
    ) {
    }

    /** Défauts techniques de premier démarrage, pas des données métier. */
    public static function defaults(): self
    {
        // Photos désactivées : elles ne figurent plus aux écrans de comptage.
        return new self('08:00', '22:00', 'Europe/Warsaw', Locale::Fr, false, false, 0.05, 0);
    }
}
