<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Le « contrôle de réalité » d'une période : ce que les ventes AURAIENT DÛ
 * coûter en matière, contre ce qui a réellement été consommé.
 *
 * ── Deux chiffres, une seule question
 *
 * Le coût THÉORIQUE se déduit des ventes : chaque tiramisu vendu descend sa
 * recette jusqu'aux matières achetées, et l'on somme. Le coût RÉEL se lit sur
 * ce qui a quitté les bacs. Quand les deux s'écartent, c'est que quelque chose
 * échappe à la recette — un gâchis, une portion trop généreuse, une casse, un
 * vol. La jauge ne dit pas lequel ; elle dit COMBIEN, et c'est déjà ce qui
 * décide s'il faut aller voir.
 *
 * ── Pourquoi la DÉRIVE, et non l'écart signé
 *
 * Consommer plus que prévu est une perte ; consommer beaucoup MOINS n'est pas
 * une bonne nouvelle mais un doute — on ne fabrique pas un tiramisu avec la
 * moitié de sa crème, donc c'est le relevé qui ment. Les deux éloignent la
 * réalité de la théorie, et c'est cet éloignement que la jauge mesure : au
 * plus il est grand, au plus le contrôle est mauvais. Le tableau, lui, garde
 * le SIGNE, pour qu'on sache de quel côté chercher.
 *
 * ── L'échelle s'arrête à 40 %
 *
 * Au-delà, la précision ne sert plus : un écart de 45 % ou de 80 % appelle la
 * même réaction — tout revoir. Border la jauge à 40 % garde de la finesse là
 * où une décision se joue (5 %, 10 %, 20 %) plutôt que de l'écraser pour loger
 * un cas extrême qui ne se lit de toute façon que « au maximum ».
 */
final readonly class RealityCheck
{
    /** L'écart au-delà duquel la jauge est pleine. 40 %, comme demandé. */
    public const SCALE_MAX = 0.40;

    private function __construct(
        public float $theoretical,
        /** `null` quand aucune consommation n'a été relevée sur la période. */
        public ?float $real,
        /** Écart signé en argent : réel − théorique. `null` sans relevé. */
        public ?float $deviation,
        /** Écart relatif signé, `null` si le théorique est nul ou le réel absent. */
        public ?float $deviationRatio,
        /** Part de la jauge remplie, de 0 à 1 sur l'échelle 0–40 %. */
        public float $fill,
        public RealitySeverity $severity,
    ) {
    }

    /**
     * Construit le contrôle d'une période.
     *
     * @param float      $theoretical coût matière déduit des ventes
     * @param float|null $real        coût matière réellement consommé, `null` si non relevé
     * @param float      $tolerance   dérive admise avant l'alerte (0.05 = 5 %),
     *                                la même que le delta technique
     */
    public static function of(float $theoretical, ?float $real, float $tolerance): self
    {
        $theoretical = max(0.0, $theoretical);

        // Pas de relevé : la jauge n'a rien à mesurer. Elle ne montre pas un
        // « zéro » rassurant — un écart nul et « on n'a rien compté » ne se
        // ressemblent que pour qui ne regarde pas — mais un état INCONNU.
        if ($real === null) {
            return new self($theoretical, null, null, null, 0.0, RealitySeverity::Unknown);
        }

        $deviation = $real - $theoretical;

        // Théorique nul mais réel non nul : on a consommé sans rien vendre qui
        // l'explique. Le pourcentage n'existe pas (division par zéro), mais la
        // dérive, elle, est maximale — c'est le pire cas, pas un cas absent.
        if ($theoretical <= 0.0) {
            $inconnu = abs($deviation) > 1e-9;

            return new self(
                $theoretical,
                $real,
                $deviation,
                null,
                $inconnu ? 1.0 : 0.0,
                $inconnu ? RealitySeverity::Danger : RealitySeverity::Ok,
            );
        }

        $ratio = $deviation / $theoretical;
        $derive = abs($ratio);

        return new self(
            $theoretical,
            $real,
            $deviation,
            $ratio,
            min($derive, self::SCALE_MAX) / self::SCALE_MAX,
            self::severityOf($derive, $tolerance),
        );
    }

    /**
     * La gravité, par paliers de la tolérance.
     *
     * Dans la tolérance : la mesure n'est jamais parfaite, un petit écart est le
     * bruit normal du comptage. Jusqu'à trois fois : ça mérite un œil. Au-delà :
     * ça mérite qu'on se déplace.
     */
    private static function severityOf(float $derive, float $tolerance): RealitySeverity
    {
        $tolerance = max(0.0, $tolerance);

        if ($derive <= $tolerance) {
            return RealitySeverity::Ok;
        }

        return $derive <= $tolerance * 3.0 ? RealitySeverity::Warn : RealitySeverity::Danger;
    }

    /** L'écart relatif en points de pourcentage, arrondi, pour l'affichage. */
    public function deviationPercent(): ?float
    {
        return $this->deviationRatio === null ? null : round($this->deviationRatio * 100, 1);
    }

    public function hasReal(): bool
    {
        return $this->real !== null;
    }
}
