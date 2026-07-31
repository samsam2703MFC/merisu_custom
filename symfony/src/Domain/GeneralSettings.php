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
    ) {
    }

    /** Défauts techniques de premier démarrage, pas des données métier. */
    public static function defaults(): self
    {
        return new self('08:00', '22:00', 'Europe/Warsaw', Locale::Fr, true, false, 0.05);
    }
}
