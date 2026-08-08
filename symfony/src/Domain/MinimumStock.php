<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Stock minimum d'une composition, déduit de ce qui s'est écoulé.
 *
 *   Minimum = moyenne(écoulé, 6 derniers mêmes jours de semaine)
 *             × (1 + correction météo)
 *             arrondi selon le produit
 *
 * ── Pourquoi le même JOUR DE SEMAINE
 *
 * Un samedi ne ressemble pas à un mardi. Faire la moyenne des quarante-deux
 * derniers jours écraserait justement la variation qu'on cherche à suivre, et
 * le samedi manquerait tandis que le mardi resterait sur les bras.
 *
 * ── Ce que « écoulé » veut dire, exactement
 *
 * Ouverture + production − clôture. C'est-à-dire les ventes ET les pertes :
 * ce module compte ce qu'il y a dans les bacs, il ne connaît ni les
 * encaissements ni les tickets. Tant que la caisse n'est pas branchée, le
 * minimum est donc calculé sur l'écoulé, ce que l'écran dit — appeler cela
 * « ventes » ferait passer la casse pour de la demande.
 *
 * ── Les jours sans comptage ne comptent pas pour zéro
 *
 * Une boutique fermée le dimanche, ou un comptage oublié, laisse un trou. Le
 * traiter comme un zéro diviserait la moyenne par deux et le rayon serait vide
 * le dimanche suivant. Ces jours sont donc ÉCARTÉS, et leur nombre remonté :
 * une moyenne sur deux relevés n'a pas la même valeur qu'une moyenne sur six.
 */
final readonly class MinimumStock
{
    /** Nombre de semaines regardées en arrière. */
    public const WEEKS = 6;

    /**
     * En deçà, on ne se substitue pas au seuil saisi à la main.
     *
     * Deux relevés font une moyenne que le premier jour de soldes suffit à
     * fausser. Le seuil manuel, lui, a été posé par quelqu'un qui connaît la
     * boutique : il vaut mieux qu'une moyenne bâtie sur presque rien.
     */
    public const MIN_SAMPLES = 3;

    private function __construct(
        /** Moyenne de l'écoulé, avant correction. */
        public float $average,
        /** Après correction météo, avant arrondi. */
        public float $adjusted,
        /** Ce qu'on retient — arrondi selon le produit. */
        public float $value,
        /** Relevés effectivement utilisés. */
        public int $samples,
        /** Jours écartés faute de comptage complet. */
        public int $skipped,
        /** Correction appliquée, en pourcentage. */
        public float $weatherPercent,
    ) {
    }

    /**
     * Calcule le minimum, ou null si l'historique ne dit rien.
     *
     * `$history` porte l'écoulé des mêmes jours de semaine, du plus récent au
     * plus ancien. `null` marque un jour sans comptage complet.
     *
     * @param list<float|null> $history
     */
    public static function of(array $history, float $weatherPercent, Product $product): ?self
    {
        $releves = [];
        $ecartes = 0;

        // On ne regarde que les six dernières semaines : au-delà, la saison a
        // changé et la moyenne parle d'une autre boutique.
        foreach (\array_slice($history, 0, self::WEEKS) as $valeur) {
            if ($valeur === null || $valeur < 0) {
                ++$ecartes;
                continue;
            }

            $releves[] = $valeur;
        }

        if ($releves === []) {
            return null;
        }

        $moyenne = array_sum($releves) / \count($releves);

        // Une correction en dessous de −100 % donnerait un minimum négatif :
        // un réglage aberrant en administration ne doit pas vider le rayon.
        $corrigee = max(0.0, $moyenne * (1 + max(-100.0, $weatherPercent) / 100));

        return new self(
            Rounding::clean($moyenne),
            Rounding::clean($corrigee),
            Rounding::apply($corrigee, $product->roundingStep, $product->roundingMode),
            \count($releves),
            $ecartes,
            $weatherPercent,
        );
    }

    /**
     * L'historique suffit-il à se substituer au seuil saisi ?
     *
     * Le calcul reste affiché en dessous du seuil : voir une moyenne bâtie sur
     * deux relevés apprend quelque chose, s'y fier automatiquement non.
     */
    public function isReliable(): bool
    {
        return $this->samples >= self::MIN_SAMPLES;
    }
}
