<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * La géométrie d'un graphique de ventes, calculée ici et non dans le gabarit.
 *
 * ── Pourquoi le dessin se calcule en PHP
 *
 * Un graphique est une affirmation sur des chiffres. Une barre deux fois plus
 * haute dit « deux fois plus », et si l'échelle est fausse elle ment avec
 * l'aplomb d'une mesure. Cette arithmétique se teste ; celle qu'on écrit dans
 * un gabarit ne se teste pas.
 *
 * Et il n'y a PAS de script : le SVG sort du serveur, déjà tracé. L'atelier
 * travaille hors ligne, et un graphique qui attend une bibliothèque à charger
 * n'aurait rien montré au poste, un matin, sans réseau.
 *
 * ── L'échelle part de ZÉRO, toujours
 *
 * Une barre tronquée à mi-hauteur transforme un écart de 5 % en montagne.
 * C'est le mensonge le plus courant des graphiques d'affaires, et il n'a pas
 * sa place là où l'on décide combien produire.
 */
final class SalesChart
{
    /**
     * Le repère du dessin, en unités SVG.
     *
     * 720 de large : à peu près la largeur rendue sur un écran de bureau, si
     * bien qu'une unité vaut à peu près un pixel et que les épaisseurs
     * prescrites — barre de 24 au plus, trait de 2 — gardent leur sens. Le SVG
     * se met à l'échelle ensuite, sans que rien ne se déforme.
     */
    public const WIDTH = 720;

    public const HEIGHT = 260;

    /** Hauteur du tracé lui-même ; le reste porte les libellés de l'axe. */
    public const PLOT = 200;

    /** Air au-dessus de la plus haute barre, pour que sa valeur respire. */
    public const TOP = 16;

    /**
     * Épaisseur maximale d'une barre.
     *
     * Bornée, et non « toute la place disponible » : une barre qui remplit sa
     * case donne un bloc massif, et la série entière se lit comme un mur.
     * L'air entre les barres fait autant pour la lecture que les barres.
     */
    public const BAR_MAX = 24;

    /** L'écart de surface qui sépare deux barres voisines. */
    public const GAP = 2;

    /**
     * Marge latérale du tracé.
     *
     * Le dernier point d'une courbe porte un disque de rayon 4 et son anneau
     * de surface : posé à l'abscisse 720, il déborde du cadre et se fait
     * rogner. Sa valeur, écrite à côté, déborde davantage. Huit unités de
     * chaque côté suffisent à ce que la marque tienne dans le dessin.
     */
    public const PAD_X = 10;

    /**
     * Le maximum de l'échelle, arrondi à un nombre qu'on lit.
     *
     * 119 devient 120, 1 843 devient 2 000. Une graduation à 118,7 n'aide
     * personne : l'axe sert à situer, pas à mesurer — c'est le tableau qui
     * mesure.
     */
    public static function niceMax(float $value): float
    {
        if ($value <= 0) {
            return 1.0;
        }

        $puissance = 10 ** floor(log10($value));
        $rapport = $value / $puissance;

        /*
          Une échelle FINE, et c'est le point.

          Le classique 1 / 2 / 5 / 10 hisse un sommet de 119 jusqu'à 200 : les
          barres n'atteignent alors que six dixièmes du cadre, et la moitié du
          graphique est du vide. Les paliers intermédiaires ramènent 119 à 120,
          où la plus haute barre touche presque le haut — c'est ce qui rend les
          écarts lisibles.
        */
        $pas = match (true) {
            $rapport <= 1.0 => 1.0,
            $rapport <= 1.2 => 1.2,
            $rapport <= 1.5 => 1.5,
            $rapport <= 2.0 => 2.0,
            $rapport <= 2.5 => 2.5,
            $rapport <= 3.0 => 3.0,
            $rapport <= 4.0 => 4.0,
            $rapport <= 5.0 => 5.0,
            $rapport <= 6.0 => 6.0,
            $rapport <= 8.0 => 8.0,
            default => 10.0,
        };

        return Rounding::clean($pas * $puissance);
    }

    /**
     * Les graduations horizontales — zéro compris, sommet compris.
     *
     * @return list<array{value: float, y: float}>
     */
    public static function ticks(float $max, int $count = 4): array
    {
        $max = self::niceMax($max);
        $lignes = [];

        for ($i = 0; $i <= $count; ++$i) {
            $valeur = $max * $i / $count;
            $lignes[] = ['value' => Rounding::clean($valeur), 'y' => self::y($valeur, $max)];
        }

        return $lignes;
    }

    /**
     * Des colonnes, une par case.
     *
     * Pour quelques cases nommées — les sept jours de la semaine, six semaines,
     * deux mois. Au-delà d'une trentaine, chaque colonne descend sous le
     * millimètre sur un téléphone : c'est alors une courbe qu'il faut.
     *
     * @param list<SalesBucket> $buckets
     *
     * @return list<array{key: string, x: float, y: float, width: float, height: float, value: float, center: float}>
     */
    public static function columns(array $buckets, bool $average = false): array
    {
        $max = self::niceMax(self::peak($buckets, $average));
        $nombre = count($buckets);

        if ($nombre === 0) {
            return [];
        }

        $bande = (self::WIDTH - self::PAD_X * 2) / $nombre;
        // La barre ne prend jamais toute sa case : l'écart de surface sépare
        // les voisines, et le plafond garde des marques fines.
        $largeur = min(self::BAR_MAX, max(4.0, $bande - self::GAP * 2));

        $colonnes = [];

        foreach (array_values($buckets) as $rang => $case) {
            $valeur = $average ? $case->averagePerDay() : $case->quantity;
            $centre = self::PAD_X + $bande * ($rang + 0.5);
            $y = self::y($valeur, $max);

            $colonnes[] = [
                'key' => $case->key,
                'x' => Rounding::clean($centre - $largeur / 2),
                'y' => Rounding::clean($y),
                'width' => Rounding::clean($largeur),
                'height' => Rounding::clean(self::TOP + self::PLOT - $y),
                'value' => $valeur,
                'center' => Rounding::clean($centre),
            ];
        }

        return $colonnes;
    }

    /**
     * Une courbe et son aplat, pour une série dense.
     *
     * Les quarante-deux jours d'un relevé de six semaines : en colonnes, elles
     * feraient huit pixels de large sur un téléphone. La ligne montre la forme
     * de la période, ce que des colonnes trop fines ne montrent plus.
     *
     * @param list<SalesBucket> $buckets
     *
     * @return array{line: string, area: string, points: list<array{key: string, x: float, y: float, value: float}>}
     */
    public static function area(array $buckets, bool $average = false): array
    {
        $max = self::niceMax(self::peak($buckets, $average));
        $nombre = count($buckets);

        if ($nombre === 0) {
            return ['line' => '', 'area' => '', 'points' => []];
        }

        // Un point unique n'a pas de segment : on lui donne toute la largeur,
        // faute de quoi la division par zéro le collerait au bord gauche.
        $utile = self::WIDTH - self::PAD_X * 2;
        $pas = $nombre > 1 ? $utile / ($nombre - 1) : $utile;

        $points = [];
        $coordonnees = [];

        foreach (array_values($buckets) as $rang => $case) {
            $valeur = $average ? $case->averagePerDay() : $case->quantity;
            $x = $nombre > 1 ? Rounding::clean(self::PAD_X + $pas * $rang) : self::WIDTH / 2;
            $y = Rounding::clean(self::y($valeur, $max));

            $points[] = ['key' => $case->key, 'x' => $x, 'y' => $y, 'value' => $valeur];
            $coordonnees[] = $x . ',' . $y;
        }

        $base = self::TOP + self::PLOT;
        $premier = $points[0];
        $dernier = $points[$nombre - 1];

        return [
            'line' => implode(' ', $coordonnees),
            // L'aplat se referme sur la ligne de base : sans cela, le tracé se
            // remplirait jusqu'au haut du cadre et l'on verrait le négatif.
            'area' => 'M' . $premier['x'] . ',' . $base
                . ' L' . implode(' L', $coordonnees)
                . ' L' . $dernier['x'] . ',' . $base . ' Z',
            'points' => $points,
        ];
    }

    /** La plus haute valeur d'un jeu de cases. */
    private static function peak(array $buckets, bool $average): float
    {
        $max = 0.0;

        foreach ($buckets as $case) {
            $max = max($max, $average ? $case->averagePerDay() : $case->quantity);
        }

        return $max;
    }

    /**
     * L'ordonnée d'une valeur.
     *
     * Zéro est en bas du tracé, le maximum en haut : l'échelle part TOUJOURS de
     * zéro. Une barre tronquée transforme un écart de 5 % en montagne.
     */
    private static function y(float $value, float $max): float
    {
        $part = $max > 0 ? max(0.0, min(1.0, $value / $max)) : 0.0;

        return Rounding::clean(self::TOP + self::PLOT * (1 - $part));
    }
}
