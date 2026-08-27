<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * La géométrie de la jauge de réalité — un demi-cercle, rendu côté serveur.
 *
 * ── Pas une ligne de JavaScript, ici non plus
 *
 * Tout le module s'en passe ; la jauge ne fera pas exception. L'arc se dessine
 * en SVG pur, et son remplissage tient à une seule astuce : `pathLength="100"`
 * fait compter le chemin de 0 à 100 quelle que soit sa vraie longueur, si bien
 * qu'un `stroke-dasharray` en pourcentage remplit exactement la bonne part
 * sans qu'on calcule où l'arc s'arrête. La trigonométrie ne sert plus que pour
 * les deux repères de seuil et l'aiguille — trois points, pas une courbe.
 *
 * ── Le demi-cercle va de la GAUCHE (0) à la DROITE (plein)
 *
 * Une jauge se lit comme un cadran : vide à gauche, pleine à droite. L'angle
 * part donc de 180° et descend vers 0°, et le remplissage suit la dérive
 * bornée à 40 %.
 */
final readonly class RealityGauge
{
    public function __construct(
        public float $cx,
        public float $cy,
        public float $r,
        /** Chemin SVG du demi-cercle, de la gauche à la droite par le haut. */
        public string $arc,
        /** Part remplie, de 0 à 100, prête pour `stroke-dasharray`. */
        public float $fillLength,
        /** Aiguille : la pointe sur l'arc, au niveau de la dérive courante. */
        public float $needleX,
        public float $needleY,
        /** Repères de seuil sur l'arc : {x1,y1,x2,y2} pour un petit trait radial. */
        public array $ticks,
    ) {
    }

    /**
     * @param float        $fill       part remplie, de 0 à 1 (déjà bornée à l'échelle)
     * @param list<float>  $thresholds fractions (0..1) où poser un repère de seuil
     */
    public static function build(float $fill, array $thresholds = []): self
    {
        $cx = 100.0;
        $cy = 90.0;
        $r = 78.0;

        $fill = max(0.0, min(1.0, $fill));

        // Arc du point GAUCHE (fraction 0) au point DROITE (fraction 1), par le
        // haut. large-arc-flag=0 (demi-tour), sweep-flag=1 (sens horaire, donc
        // par-dessus). Les extrémités reposent exactement sur la ligne de base.
        $g = self::point($cx, $cy, $r, 0.0);
        $d = self::point($cx, $cy, $r, 1.0);
        $arc = sprintf(
            'M %s %s A %s %s 0 0 1 %s %s',
            self::n($g[0]), self::n($g[1]),
            self::n($r), self::n($r),
            self::n($d[0]), self::n($d[1]),
        );

        $aiguille = self::point($cx, $cy, $r, $fill);

        $ticks = [];
        foreach ($thresholds as $t) {
            $t = max(0.0, min(1.0, $t));
            $interne = self::point($cx, $cy, $r - 9.0, $t);
            $externe = self::point($cx, $cy, $r + 2.0, $t);
            $ticks[] = [
                'x1' => self::n($interne[0]), 'y1' => self::n($interne[1]),
                'x2' => self::n($externe[0]), 'y2' => self::n($externe[1]),
            ];
        }

        return new self($cx, $cy, $r, $arc, $fill * 100.0, self::n($aiguille[0]), self::n($aiguille[1]), $ticks);
    }

    /**
     * Le point de l'arc à une fraction donnée.
     *
     * Fraction 0 = extrémité gauche (angle 180°), fraction 1 = extrémité droite
     * (angle 0°). En SVG l'axe y descend, d'où le `−sin`.
     *
     * @return array{0: float, 1: float}
     */
    private static function point(float $cx, float $cy, float $r, float $fraction): array
    {
        $angle = deg2rad(180.0 - 180.0 * $fraction);

        return [$cx + $r * cos($angle), $cy - $r * sin($angle)];
    }

    private static function n(float $v): float
    {
        return round($v, 2);
    }
}
