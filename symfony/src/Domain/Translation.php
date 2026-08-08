<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qu'il reste à traduire, et ce qu'on a le droit d'écrire.
 *
 * Toute la décision est ici, hors de l'appel réseau : quelle langue sert de
 * source, quelles langues manquent, et — la règle qui compte — ce qu'une
 * traduction automatique n'a PAS le droit de toucher.
 *
 * ── Elle ne remplit que les trous
 *
 * Une traduction produite par une machine ne vaut pas celle qu'un vendeur
 * polonais a écrite en connaissant le produit. Écraser aurait été la manière
 * la plus rapide de rendre l'outil inutilisable : on aurait perdu, à chaque
 * clic, le travail de quelqu'un.
 *
 * La contrepartie est assumée : corriger le nom français ne corrige pas les
 * trois traductions déjà en place, qui deviennent alors DÉCALÉES. Pour les
 * refaire, on vide le champ dans la langue concernée — le geste est explicite,
 * et c'est bien ce qu'on veut d'un effacement.
 */
final class Translation
{
    /**
     * La langue qui sert de source.
     *
     * Celle de l'écran d'abord : c'est celle qu'on vient de saisir, et la
     * seule que l'administration montre. Si elle est vide — on a saisi la
     * fiche en polonais puis basculé en français —, on prend la langue la
     * mieux remplie plutôt que d'abandonner.
     *
     * @param array<string,array<string,string>> $fields champ => langue => texte
     */
    public static function source(array $fields, Locale $preferred): ?Locale
    {
        $meilleure = null;
        $score = 0;

        foreach (Locale::all() as $locale) {
            $rempli = 0;

            foreach ($fields as $values) {
                if (self::isFilled($values[$locale->value] ?? null)) {
                    ++$rempli;
                }
            }

            if ($rempli === 0) {
                continue;
            }

            if ($locale === $preferred) {
                return $locale;
            }

            if ($rempli > $score) {
                $meilleure = $locale;
                $score = $rempli;
            }
        }

        return $meilleure;
    }

    /**
     * Le travail à faire : les textes à envoyer et les langues à obtenir.
     *
     * Ne porte QUE ce qui manque : un champ déjà traduit dans les quatre
     * langues ne repart pas, même quand ses voisins, eux, ont des trous.
     *
     * Rend un plan VIDE — source nulle — dès qu'il n'y a rien à faire : aucune
     * source, ou déjà tout traduit. L'appelant n'a donc pas à décider s'il
     * appelle l'API ; il regarde la source.
     *
     * @param array<string,array<string,string>> $fields champ => langue => texte
     *
     * @return array{source: ?Locale, texts: array<string,string>, targets: list<Locale>}
     */
    public static function plan(array $fields, Locale $preferred): array
    {
        $vide = ['source' => null, 'texts' => [], 'targets' => []];

        $source = self::source($fields, $preferred);
        if ($source === null) {
            return $vide;
        }

        // Un champ n'entre dans la requête qu'à DEUX conditions : être
        // renseigné dans la source, et manquer quelque part.
        //
        // La première écarte ce qu'on ne saurait pas traduire — partir du vide
        // produirait une invention, pas une traduction. La seconde écarte ce
        // qui n'a plus rien à recevoir : le nom d'un produit se traduit une
        // fois, les ingrédients se remanient à chaque saison, et sans ce
        // filtre le nom repartait à chaque retouche de l'étiquette pour que sa
        // traduction soit ensuite jetée — elle existait déjà, et une
        // traduction existante n'est jamais remplacée.
        $textes = [];
        $manquantes = [];

        foreach ($fields as $champ => $values) {
            $texte = $values[$source->value] ?? '';
            if (!self::isFilled($texte)) {
                continue;
            }

            $trous = self::missing($values);
            if ($trous === []) {
                continue;
            }

            $textes[$champ] = trim($texte);

            foreach ($trous as $locale) {
                $manquantes[$locale->value] = $locale;
            }
        }

        if ($textes === []) {
            return $vide;
        }

        // Les langues demandées, dans l'ordre du module et non dans celui où
        // les champs les ont réclamées : c'est cet ordre que le compte rendu
        // affiche, et il n'a pas à dépendre du champ qui manquait le premier.
        $cibles = array_values(array_filter(
            Locale::all(),
            static fn (Locale $l): bool => isset($manquantes[$l->value]),
        ));

        return ['source' => $source, 'texts' => $textes, 'targets' => $cibles];
    }

    /**
     * Repose les traductions SANS écraser ce qui existe.
     *
     * @param array<string,string> $values   ce qui est déjà en base
     * @param array<string,string> $produced langue => texte proposé
     *
     * @return array<string,string>
     */
    public static function fill(array $values, array $produced): array
    {
        $sortie = $values;

        foreach ($produced as $langue => $texte) {
            // Une langue inconnue rendue par l'hôte n'entre pas en base : elle
            // s'afficherait nulle part et resterait là sans qu'on la voie.
            if (Locale::tryFrom((string) $langue) === null) {
                continue;
            }

            if (self::isFilled($sortie[$langue] ?? null) || !self::isFilled($texte)) {
                continue;
            }

            $sortie[$langue] = trim($texte);
        }

        return $sortie;
    }

    /**
     * Les langues encore absentes d'un champ.
     *
     * Sert à DIRE le résultat : « polonais et italien écrits, espagnol
     * toujours manquant » vaut mieux qu'un « traduit » qui laisse vérifier
     * langue par langue.
     *
     * @param array<string,string> $values
     *
     * @return list<Locale>
     */
    public static function missing(array $values): array
    {
        $manquantes = [];

        foreach (Locale::all() as $locale) {
            if (!self::isFilled($values[$locale->value] ?? null)) {
                $manquantes[] = $locale;
            }
        }

        return $manquantes;
    }

    /** Un champ blanc est un champ vide : « &nbsp; » ne traduit rien. */
    private static function isFilled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
