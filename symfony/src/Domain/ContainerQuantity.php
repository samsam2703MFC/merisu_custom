<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Traduction entre ce que voit le vendeur et ce que stocke la base.
 *
 * La base garde une quantité décimale — 2,75 bacs — parce que tout le reste du
 * module compte ainsi : la variation nette, le plan de production, l'écart
 * technique. Rien de tout cela ne doit connaître l'existence des quarts.
 *
 * L'écran, lui, ne montre jamais 2,75 : il montre deux bacs pleins et un
 * troisième aux trois quarts, parce que c'est ce que le vendeur a sous les yeux.
 * Cette classe fait l'aller-retour, et elle seule.
 */
final class ContainerQuantity
{
    /**
     * Fractions proposées à l'écran.
     *
     * Quatre choix et pas davantage : au poste on estime un niveau d'un coup
     * d'œil, on ne le mesure pas. Proposer 10 % puis 20 % donnerait une fausse
     * précision et allongerait la saisie d'autant.
     *
     * @var list<int>
     */
    public const FRACTIONS = [0, 25, 50, 75];

    /**
     * Sépare une quantité en contenants pleins et pourcentage du dernier.
     *
     * @return array{whole: int, percent: int}
     */
    public static function split(?float $quantity): array
    {
        if ($quantity === null || $quantity < 0) {
            return ['whole' => 0, 'percent' => 0];
        }

        $whole = (int) floor($quantity);
        $reste = $quantity - $whole;

        // On ramène le reste à la fraction proposée la plus proche : une
        // quantité venue d'ailleurs (import, saisie hors-ligne, ancien module)
        // ne doit pas se présenter comme un choix impossible à l'écran.
        $percent = (int) (round($reste * 100 / 25) * 25);

        // Un reste arrondi à 100 % est un contenant plein de plus.
        if ($percent >= 100) {
            ++$whole;
            $percent = 0;
        }

        return ['whole' => $whole, 'percent' => $percent];
    }

    /** Recompose la quantité décimale à partir de la saisie de l'écran. */
    public static function combine(?int $whole, ?int $percent): ?float
    {
        // Aucun des deux renseigné : absence de saisie, pas un zéro. La
        // distinction porte tout le reste — un produit non compté ne doit
        // jamais passer pour un stock épuisé.
        if ($whole === null && $percent === null) {
            return null;
        }

        $whole = max(0, $whole ?? 0);
        $percent = self::nearestFraction($percent ?? 0);

        return $whole + $percent / 100;
    }

    /** Fraction proposée la plus proche d'un pourcentage quelconque. */
    public static function nearestFraction(int $percent): int
    {
        if ($percent >= 100) {
            return 75;
        }

        $percent = max(0, $percent);
        $plusProche = self::FRACTIONS[0];

        foreach (self::FRACTIONS as $fraction) {
            if (abs($fraction - $percent) < abs($plusProche - $percent)) {
                $plusProche = $fraction;
            }
        }

        return $plusProche;
    }
}
