<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Avancement d'un objectif mensuel — la jauge tiramisu de l'écran Réseau.
 *
 * Deux nombres seulement, mais trois pièges :
 *
 * · un objectif à zéro. Diviser par lui ferait tomber l'écran, et le forcer à
 *   1 afficherait « 4 200 % » le premier jour. Sans objectif, pas de jauge.
 * · un objectif dépassé. La BARRE s'arrête à 100 % — au-delà elle sortirait
 *   de sa boîte — mais le POURCENTAGE affiché garde sa vraie valeur : une
 *   équipe à 130 % a le droit de le lire.
 * · le rythme. « 600 sur 1 000 » ne dit rien seul : c'est excellent le 10 du
 *   mois, inquiétant le 28. D'où la comparaison au temps écoulé.
 */
final readonly class MonthlyTarget
{
    private function __construct(
        public int $sold,
        public int $target,
        /** Pourcentage réel, non plafonné — peut dépasser 100. */
        public int $percent,
        /** Pourcentage pour la largeur de barre, borné à [0, 100]. */
        public int $barPercent,
        public bool $reached,
        /** Part du mois écoulée, en pourcentage. Repère de rythme. */
        public int $monthElapsed,
        /** Vrai si l'on avance au moins aussi vite que le calendrier. */
        public bool $onTrack,
    ) {
    }

    /**
     * @param int $dayOfMonth  Jour courant, 1 à 31
     * @param int $daysInMonth Longueur du mois, 28 à 31
     */
    public static function of(int $sold, int $target, int $dayOfMonth, int $daysInMonth): ?self
    {
        // Pas d'objectif fixé : rien à montrer. C'est un réglage laissé vide
        // en administration, pas une erreur.
        if ($target <= 0) {
            return null;
        }

        $sold = max(0, $sold);
        $daysInMonth = max(1, $daysInMonth);
        $dayOfMonth = min(max(1, $dayOfMonth), $daysInMonth);

        $percent = (int) round($sold / $target * 100);
        $elapsed = (int) round($dayOfMonth / $daysInMonth * 100);

        return new self(
            $sold,
            $target,
            $percent,
            min(100, max(0, $percent)),
            $sold >= $target,
            $elapsed,
            $percent >= $elapsed,
        );
    }

    /** Ce qu'il reste à vendre pour atteindre l'objectif. Zéro une fois atteint. */
    public function remaining(): int
    {
        return max(0, $this->target - $this->sold);
    }
}
