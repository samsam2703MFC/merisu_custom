<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * La géométrie des courbes du réseau, calculée au SERVEUR.
 *
 * Tout le module se passe de JavaScript : un poste dont le script n'a pas
 * chargé doit continuer d'afficher ses chiffres. Les points des polylignes
 * sont donc calculés ici, et le gabarit ne fait que les écrire.
 *
 * ── Pourquoi le CUMUL, et pas seulement les ventes du jour
 *
 * Les ventes quotidiennes sautent trop pour qu'on y lise une tendance : un
 * samedi à 489 et un lundi à 195 font une dent de scie où l'œil ne trouve
 * rien. Le cumul, lui, ne descend jamais, et se compare à une droite — celle
 * de l'objectif. On voit d'un coup si l'on passe au-dessus ou au-dessous, et
 * depuis quand.
 *
 * ── L'objectif est une DROITE, et c'est une convention assumée
 *
 * Le rythme d'objectif suppose que le mois se vend régulièrement. C'est faux :
 * les week-ends pèsent plus. La droite reste néanmoins le bon repère, parce
 * qu'elle répond à la seule question qu'on lui pose — « à ce rythme,
 * arrive-t-on au bout ? » — et qu'une courbe d'objectif épousant la saison
 * ferait passer un retard pour une avance un lundi matin.
 */
final readonly class NetworkChart
{
    public const WIDTH = 720;
    public const HEIGHT = 240;

    /** Hauteur utile, sous le titre et au-dessus des dates. */
    public const PLOT = 176;
    public const TOP = 12;

    /**
     * Marge latérale.
     *
     * La première et la dernière date sont écrites sous leur point ; sans
     * cette marge, la moitié de « 01-08 » sortait du cadre — le même défaut
     * que sur les graphiques de Ventes, corrigé de la même façon.
     */
    public const PAD_X = 28;

    /**
     * Les couleurs des boutiques, dans un ORDRE FIXE.
     *
     * Jamais recyclées, jamais réattribuées : une boutique garde sa couleur
     * quand on filtre, sinon un filtre repeindrait les survivantes et l'on
     * croirait lire une autre boutique. Au-delà de six, les suivantes
     * rejoignent « Autres » plutôt que d'inventer une teinte.
     *
     * Validées ensemble — bande de clarté, plancher de saturation, séparation
     * sous daltonisme, contraste sur fond blanc.
     */
    public const COLORS = ['#C4306A', '#3168C8', '#9A6E00', '#0D8B66', '#7B4FC0', '#B8541F'];

    /**
     * @param list<string>          $dates
     * @param list<float>           $cumulative  cumul du réseau, jour après jour
     * @param list<float>           $daily       ventes de chaque jour
     * @param array<string, string> $series      nom de boutique => points de sa polyligne
     * @param array<string, string> $colors      nom de boutique => couleur
     */
    private function __construct(
        public array $dates,
        public array $cumulative,
        public array $daily,
        public array $series,
        public array $colors,
        public float $max,
        public float $total,
        public ?float $target,
        public float $average,
        public string $cumulativePoints,
        public string $areaPoints,
        public ?string $targetLine,
        public string $averageLine,
    ) {
    }

    /**
     * @param list<string>                        $dates    les journées de la période, dans l'ordre
     * @param array<string, array<string, float>> $byShop   nom de boutique => (date => quantité)
     */
    public static function build(array $dates, array $byShop, ?float $target): self
    {
        $n = \count($dates);

        if ($n === 0) {
            return new self([], [], [], [], [], 0.0, 0.0, null, 0.0, '', '', null, '');
        }

        // ── Le réseau, jour par jour puis cumulé ────────────────────────────
        $quotidien = [];
        $cumul = [];
        $courant = 0.0;

        foreach ($dates as $date) {
            $duJour = 0.0;
            foreach ($byShop as $jours) {
                $duJour += $jours[$date] ?? 0.0;
            }

            $quotidien[] = $duJour;
            $courant += $duJour;
            $cumul[] = $courant;
        }

        $total = $courant;

        // L'échelle porte le cumul ET l'objectif : une droite d'objectif qui
        // sortirait du cadre ne dirait plus de combien on est en retard.
        $max = max($total, $target ?? 0.0);
        $max = $max > 0.0 ? $max : 1.0;

        $x = static fn (int $i): float => $n === 1
            ? self::WIDTH / 2
            : self::PAD_X + ($i * (self::WIDTH - 2 * self::PAD_X)) / ($n - 1);
        $y = static fn (float $v): float => self::TOP + self::PLOT - ($v / $max) * self::PLOT;

        $points = static function (array $valeurs) use ($x, $y): string {
            $morceaux = [];
            foreach ($valeurs as $i => $v) {
                $morceaux[] = round($x($i), 1) . ',' . round($y($v), 1);
            }

            return implode(' ', $morceaux);
        };

        // ── Chaque boutique, cumulée pour elle-même ─────────────────────────
        $series = [];
        $couleurs = [];
        $rang = 0;

        foreach ($byShop as $nom => $jours) {
            $c = 0.0;
            $valeurs = [];

            foreach ($dates as $date) {
                $c += $jours[$date] ?? 0.0;
                $valeurs[] = $c;
            }

            $series[$nom] = $points($valeurs);
            $couleurs[$nom] = self::COLORS[$rang % \count(self::COLORS)];
            ++$rang;
        }

        $cumulPoints = $points($cumul);

        // L'aire referme la courbe sur la ligne de base : c'est elle qui donne
        // le volume, la ligne seule paraissant flotter.
        $aire = round($x(0), 1) . ',' . round(self::TOP + self::PLOT, 1)
            . ' ' . $cumulPoints
            . ' ' . round($x($n - 1), 1) . ',' . round(self::TOP + self::PLOT, 1);

        $ligneObjectif = $target === null || $target <= 0.0 ? null : \sprintf(
            '%s,%s %s,%s',
            round($x(0), 1), round($y(0.0), 1),
            round($x($n - 1), 1), round($y($target), 1),
        );

        // La moyenne se lit sur l'échelle des BARRES, pas du cumul : elle est
        // rendue en fraction de la hauteur, à charge pour le gabarit de la
        // poser dans son propre repère.
        $moyenne = $total / $n;
        $maxJour = max($quotidien) ?: 1.0;
        $yMoyenne = round(self::TOP + self::PLOT - ($moyenne / $maxJour) * self::PLOT, 1);

        return new self(
            $dates,
            $cumul,
            $quotidien,
            $series,
            $couleurs,
            $max,
            $total,
            $target,
            round($moyenne, 1),
            $cumulPoints,
            $aire,
            $ligneObjectif,
            \sprintf('%s,%s %s,%s', self::PAD_X, $yMoyenne, self::WIDTH - self::PAD_X, $yMoyenne),
        );
    }

    /**
     * Les barres du jour, prêtes à poser.
     *
     * @return list<array{x: float, y: float, w: float, h: float, date: string, value: float}>
     */
    public function bars(): array
    {
        $n = \count($this->dates);

        if ($n === 0) {
            return [];
        }

        $maxJour = max($this->daily) ?: 1.0;
        $largeur = (self::WIDTH - 2 * self::PAD_X) / $n;
        // Deux pixels de fond entre deux barres : collées, une longue série
        // devient un aplat où l'on ne distingue plus les journées.
        $barre = max(1.0, $largeur - 2);

        $barres = [];

        foreach ($this->daily as $i => $v) {
            // La hauteur est arrondie D'ABORD, et l'ordonnée s'en déduit :
            // arrondir les deux séparément faisait dépasser la barre du tracé
            // d'un dixième de pixel, et le pied ne reposait plus sur la ligne
            // de base commune.
            $h = round(max(0.0, ($v / $maxJour) * self::PLOT), 1);

            $barres[] = [
                'x' => round(self::PAD_X + $i * $largeur + ($largeur - $barre) / 2, 1),
                'y' => self::TOP + self::PLOT - $h,
                'w' => round($barre, 1),
                'h' => $h,
                'date' => $this->dates[$i],
                'value' => $v,
            ];
        }

        return $barres;
    }

    /**
     * Où en est le réseau par rapport au rythme attendu, en %.
     *
     * Null sans objectif : afficher « 0 % » laisserait croire à un échec là où
     * il n'y a simplement rien à viser.
     */
    public function pace(): ?float
    {
        if ($this->target === null || $this->target <= 0.0) {
            return null;
        }

        return round(($this->total / $this->target) * 100, 1);
    }

    /**
     * Les dates à ÉCRIRE sous l'axe.
     *
     * Trente et une dates se chevauchent en un pavé illisible : on n'en garde
     * que cinq, réparties, dont toujours la première et la dernière — ce sont
     * les deux bornes qu'on cherche en premier.
     *
     * @return array<int, string> indice du point => date
     */
    public function axisLabels(): array
    {
        $n = \count($this->dates);

        if ($n === 0) {
            return [];
        }

        if ($n <= 5) {
            return $this->dates;
        }

        $pas = ($n - 1) / 4;
        $retenues = [];

        for ($k = 0; $k <= 4; $k++) {
            $i = (int) round($k * $pas);
            $retenues[$i] = $this->dates[$i];
        }

        return $retenues;
    }

    /** L'abscisse d'un point, pour poser une étiquette dessous. */
    public function xOf(int $index): float
    {
        $n = \count($this->dates);

        if ($n <= 1) {
            return self::WIDTH / 2;
        }

        return round(self::PAD_X + ($index * (self::WIDTH - 2 * self::PAD_X)) / ($n - 1), 1);
    }
}
