<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\Locale;

/**
 * ADAPTATEUR SORTANT — traduction assistée des libellés saisis.
 *
 * Il ne traduit PAS l'interface : les chaînes d'écran vivent dans
 * `translations/` et sont écrites à la main, une fois pour toutes. Il traduit
 * les DONNÉES de la boutique — noms de produits, ingrédients, allergènes,
 * consignes du jour — qui changent toutes les semaines et que personne ne
 * retapera quatre fois.
 *
 * Le module exige quatre langues (§2). Sans aide, la fiche produit demandait
 * douze champs de libellé et l'on en remplissait trois : les trois autres
 * langues affichaient le nom français, ou rien.
 *
 * ── Ce que l'implémentation doit garantir
 *
 * Un appel, tous les champs à traduire, toutes les langues manquantes. Le
 * regroupement n'est pas un détail d'optimisation : une liste d'ingrédients et
 * les allergènes qui en découlent se traduisent mieux ensemble que séparément.
 *
 * Ce qui n'a rien à recevoir ne voyage pas pour autant : un champ déjà traduit
 * partout reste à quai. Le contexte qu'il apportait, lui, passe par
 * `$context` — c'est une phrase, pas un champ, et elle ne coûte pas une
 * traduction dont on ne ferait rien.
 */
interface AutoTranslatorInterface
{
    /**
     * Traduit plusieurs textes d'un coup, vers plusieurs langues.
     *
     * @param array<string,string> $texts   clé métier => texte source
     *                                      (« name », « ingredients »…)
     * @param list<Locale>         $targets langues attendues en retour
     * @param string               $context ce que sont ces textes, en clair —
     *                                      « nom d'un dessert », « consigne
     *                                      affichée au poste ». Deux mots
     *                                      suffisent, et ils décident du
     *                                      registre de la traduction.
     *
     * @return array<string,array<string,string>> clé métier => langue => texte
     *
     * @throws TranslationUnavailable service non configuré, injoignable, ou
     *                                réponse inexploitable — l'appelant
     *                                n'écrit rien et le dit
     */
    public function translate(array $texts, Locale $source, array $targets, string $context): array;

    /**
     * Une clé est-elle en place ?
     *
     * Interrogé par les écrans AVANT d'afficher le bouton : proposer une
     * action qui échouera à tous les coups vaut moins que ne rien proposer,
     * et l'administration a de quoi expliquer ce qui manque.
     */
    public function isConfigured(): bool;
}
