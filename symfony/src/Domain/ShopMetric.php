<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Un indicateur suivi par boutique — chiffre d'affaires, tickets, panier moyen.
 *
 * Le module n'en connaît AUCUN en dur (§2) : la liste se tient en
 * administration, parce que le réseau change ses indicateurs plus souvent
 * qu'on ne redéploie.
 *
 * ── Pourquoi `lowerIsBetter`
 *
 * Un objectif de vente se dépasse, un temps d'attente se réduit. Sans ce
 * drapeau, l'écran ne saurait pas de quel côté colorer un résultat, et
 * afficherait « objectif atteint » à une boutique qui a mis trois fois plus de
 * temps que prévu à servir.
 *
 * La clé suit le format de l'hôte (`metric_key`) : minuscules, sans espace.
 * C'est elle qui reliera l'indicateur local à celui de TF Buddy le jour du
 * branchement, et un libellé traduit n'aurait pas pu jouer ce rôle.
 */
final readonly class ShopMetric
{
    public function __construct(
        public string $key,
        public string $label,
        public string $unit,
        public bool $lowerIsBetter,
        public int $sortOrder = 0,
    ) {
    }

    /**
     * Nettoie une clé saisie à l'écran.
     *
     * Les espaces et les majuscules disparaissent, les accents aussi : cette
     * chaîne voyagera dans une URL et dans un corps JSON vers un hôte dont on
     * ne maîtrise pas la tolérance. Rend une chaîne vide si rien ne subsiste —
     * l'appelant refuse alors la saisie plutôt que d'écrire une ligne sans
     * clé, qu'aucun écran ne saurait plus retrouver.
     */
    public static function cleanKey(string $raw): string
    {
        $sans = strtr(
            mb_strtolower(trim($raw)),
            ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
             'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c'],
        );

        $propre = preg_replace('/[^a-z0-9_]+/', '_', $sans) ?? '';

        return mb_substr(trim($propre, '_'), 0, 64);
    }
}
