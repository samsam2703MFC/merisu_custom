<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Traduction entre ce que voit le vendeur et ce que stocke la base.
 *
 * La base garde une quantité décimale — 2,625 bacs — parce que tout le reste du
 * module compte ainsi : la variation nette, le plan de production, l'écart
 * technique. Rien de tout cela ne doit connaître l'existence des huitièmes.
 *
 * L'écran, lui, ne montre jamais 2,625 : il montre deux bacs pleins et un
 * troisième aux cinq huitièmes, parce que c'est ce que le vendeur a sous les
 * yeux. Cette classe fait l'aller-retour, et elle seule.
 */
final class ContainerQuantity
{
    /**
     * Fractions proposées à l'écran, par huitièmes.
     *
     * Le quart était trop grossier : un bac aux trois huitièmes se saisissait
     * « un quart » ou « la moitié », soit 12,5 % d'erreur sur un contenant —
     * assez pour fausser un plan de production sur les gros volumes.
     *
     * Le huitième reste estimable à l'œil : c'est la moitié d'un quart, et un
     * niveau se repère à mi-chemin entre deux graduations sans hésiter. Le
     * dixième, lui, demanderait de mesurer.
     *
     * Ce sont des multiples exacts de 1/8 : représentés sans perte en
     * flottant, contrairement à des dixièmes.
     *
     * @var list<float>
     */
    public const FRACTIONS = [0.0, 12.5, 25.0, 37.5, 50.0, 62.5, 75.0, 87.5];

    /** Pas entre deux graduations, en pourcentage. */
    private const PAS = 12.5;

    /**
     * Sépare une quantité en contenants pleins et pourcentage du dernier.
     *
     * @return array{whole: int, percent: float}
     */
    public static function split(?float $quantity): array
    {
        if ($quantity === null || $quantity < 0) {
            return ['whole' => 0, 'percent' => 0.0];
        }

        $whole = (int) floor($quantity);
        $reste = $quantity - $whole;

        // On ramène le reste à la graduation la plus proche : une quantité
        // venue d'ailleurs (import, saisie hors-ligne, ancien module) ne doit
        // pas se présenter comme un choix impossible à l'écran.
        $percent = round($reste * 100 / self::PAS) * self::PAS;

        // Un reste arrondi à 100 % est un contenant plein de plus.
        if ($percent >= 100.0) {
            ++$whole;
            $percent = 0.0;
        }

        return ['whole' => $whole, 'percent' => $percent];
    }

    /** Recompose la quantité décimale à partir de la saisie de l'écran. */
    public static function combine(?int $whole, ?float $percent): ?float
    {
        // Aucun des deux renseigné : absence de saisie, pas un zéro. La
        // distinction porte tout le reste — un produit non compté ne doit
        // jamais passer pour un stock épuisé.
        if ($whole === null && $percent === null) {
            return null;
        }

        $whole = max(0, $whole ?? 0);
        $percent = self::nearestFraction($percent ?? 0.0);

        return $whole + $percent / 100;
    }

    /** Graduation la plus proche d'un pourcentage quelconque. */
    public static function nearestFraction(float $percent): float
    {
        // Au-delà de la dernière graduation, on ne remonte pas à 100 % : ce
        // serait un contenant plein, qui relève du compteur, pas de la
        // fraction. La plus haute fraction est donc le plafond.
        if ($percent >= 100.0) {
            return self::FRACTIONS[\count(self::FRACTIONS) - 1];
        }

        $percent = max(0.0, $percent);
        $plusProche = self::FRACTIONS[0];

        foreach (self::FRACTIONS as $fraction) {
            if (abs($fraction - $percent) < abs($plusProche - $percent)) {
                $plusProche = $fraction;
            }
        }

        return $plusProche;
    }
}
