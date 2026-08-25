<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qu'une boutique a fait sur la période, face à son objectif.
 *
 * ── L'objectif est MENSUEL, la période ne l'est pas forcément
 *
 * Comparer les ventes de dix jours à un objectif de mois annoncerait un retard
 * tous les 10 du mois, et personne ne regarderait plus l'indicateur. La cible
 * est donc ramenée AU PRORATA des journées écoulées : à mi-mois, on se compare
 * à la moitié de l'objectif.
 *
 * ── Sans objectif, pas de jauge
 *
 * Zéro veut dire « aucun objectif fixé », pas « objectif de zéro ». La jauge
 * disparaît alors, plutôt que d'afficher une barre pleine ou une division par
 * zéro.
 */
final readonly class ShopResult
{
    public function __construct(
        public Shop $shop,
        public float $quantity,
        public float $revenue,
        /** Journées effectivement relevées sur la période. */
        public int $days,
        /** L'objectif ramené au prorata, ou null s'il n'y en a pas. */
        public ?float $target,
    ) {
    }

    public function hasTarget(): bool
    {
        return $this->target !== null && $this->target > 0.0;
    }

    /**
     * L'avancement sur l'objectif, en pourcentage.
     *
     * Non borné à 100 : une boutique qui dépasse doit le voir. C'est la JAUGE
     * qu'on borne, pas le chiffre — l'un est un dessin, l'autre une mesure.
     */
    public function progress(): ?float
    {
        return $this->hasTarget() ? Rounding::clean($this->quantity / $this->target * 100) : null;
    }

    /** La part de la jauge à remplir, elle, ne dépasse jamais le bord. */
    public function gauge(): float
    {
        $avance = $this->progress();

        return $avance === null ? 0.0 : max(0.0, min(100.0, $avance));
    }

    /** Ce qui se vend un jour ordinaire de la période. */
    public function perDay(): float
    {
        return $this->days > 0 ? round($this->quantity / $this->days, 1) : 0.0;
    }

    /**
     * Le classement d'une liste de résultats.
     *
     * Sur la QUANTITÉ, pas sur la recette : c'est le tiramisu qu'on produit, et
     * une boutique qui vend cher n'est pas celle qui produit le plus. Le
     * chiffre d'affaires reste affiché, il ne classe pas.
     *
     * @param list<self> $results
     *
     * @return list<self>
     */
    public static function rank(array $results): array
    {
        usort($results, static fn (self $a, self $b): int => [$b->quantity, $a->shop->name] <=> [$a->quantity, $b->shop->name]);

        return $results;
    }
}
