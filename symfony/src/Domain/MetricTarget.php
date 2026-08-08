<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * L'objectif d'un indicateur, pour une boutique et un mois : TROIS seuils.
 *
 * Pas un chiffre unique, et ce n'est pas un détail de présentation — c'est la
 * forme qu'attend l'hôte (`threshold_1`, `threshold_2`, `threshold_3`), et
 * c'est aussi ce qui rend un objectif lisible : le premier seuil est le
 * minimum acceptable, le troisième l'excellence. Un objectif unique ne dit que
 * « atteint » ou « raté », là où trois disent de combien.
 *
 * ── L'ORDRE des seuils suit le sens de l'indicateur
 *
 * Un chiffre d'affaires monte : 1 < 2 < 3. Un temps d'attente descend :
 * 1 > 2 > 3. C'est `ShopMetric::lowerIsBetter` qui tranche, et rien d'autre —
 * imposer partout un ordre croissant aurait obligé à saisir « moins de trois
 * minutes » comme une valeur plus grande que « moins de cinq ».
 */
final readonly class MetricTarget
{
    private function __construct(
        public string $metricKey,
        public float $threshold1,
        public float $threshold2,
        public float $threshold3,
    ) {
    }

    /** Rien à enregistrer tant que les trois seuils ne sont pas des nombres utilisables. */
    public static function of(string $metricKey, float $t1, float $t2, float $t3): ?self
    {
        $cle = ShopMetric::cleanKey($metricKey);

        if ($cle === '') {
            return null;
        }

        foreach ([$t1, $t2, $t3] as $seuil) {
            if (!is_finite($seuil)) {
                return null;
            }
        }

        return new self($cle, $t1, $t2, $t3);
    }

    /**
     * Les seuils sont-ils rangés dans le sens de l'indicateur ?
     *
     * Le désordre n'EMPÊCHE pas d'enregistrer — une boutique peut vouloir un
     * palier plat, et refuser la saisie ferait perdre le reste de la grille.
     * Mais l'écran le signale : trois seuils décroissants sur un chiffre
     * d'affaires sont presque toujours deux colonnes interverties.
     */
    public function isOrdered(bool $lowerIsBetter): bool
    {
        return $lowerIsBetter
            ? $this->threshold1 >= $this->threshold2 && $this->threshold2 >= $this->threshold3
            : $this->threshold1 <= $this->threshold2 && $this->threshold2 <= $this->threshold3;
    }

    /** Le seuil atteint par une valeur : 0 (aucun) à 3. */
    public function reached(float $value, bool $lowerIsBetter): int
    {
        $atteint = 0;

        foreach ([$this->threshold1, $this->threshold2, $this->threshold3] as $rang => $seuil) {
            if ($lowerIsBetter ? $value <= $seuil : $value >= $seuil) {
                $atteint = $rang + 1;
            }
        }

        return $atteint;
    }

    /**
     * La ligne telle que l'hôte l'attend.
     *
     * Les noms sont ceux du contrat TF Buddy (`ConsultantTargetsWrite`), et
     * non ceux de nos propriétés : c'est ici, et à un seul endroit, que le
     * vocabulaire du module rejoint celui de l'hôte.
     *
     * @return array{metric_key: string, threshold_1: float, threshold_2: float, threshold_3: float}
     */
    public function toHost(): array
    {
        return [
            'metric_key' => $this->metricKey,
            'threshold_1' => $this->threshold1,
            'threshold_2' => $this->threshold2,
            'threshold_3' => $this->threshold3,
        ];
    }
}
