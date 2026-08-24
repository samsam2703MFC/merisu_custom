<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ranger le catalogue : chaque produit auprès des siens.
 *
 * ── Le désordre que cela répare
 *
 * La caisse rend ses articles par ORDRE ALPHABÉTIQUE. Repris tels quels, les
 * rayons s'entremêlent — « Berries Extra » (Premium), puis « CaffeMisù »
 * (Coffee), puis « Coffee NapLess » (Coffee Beans) — et le vendeur qui compte
 * le frigo saute d'une étagère à l'autre à chaque ligne.
 *
 * Pire : l'alphabet inverse la progression des tailles. Extra, Grande,
 * Regular, alors qu'on les range et qu'on les compte dans l'autre sens.
 *
 * ── Deux clés, et la seconde n'est pas le nom
 *
 * · le RAYON d'abord, dans l'ordre qu'Admin ▸ Catégories a posé. C'est
 *   l'ordre du magasin, celui que l'atelier a décidé ; le déduire des noms
 *   aurait rangé le catalogue par l'alphabet une seconde fois ;
 *
 * · puis la RÉFÉRENCE de la caisse, lue comme un nombre. C'est l'ordre dans
 *   lequel la boutique a créé ses articles, et il porte une information que
 *   le nom n'a pas : Traditional Regular (1), Grande (2), Extra (3) se
 *   suivent, comme Salted Caramel Regular (9), Grande (10), Extra (11). Une
 *   gamme se tient ainsi groupée, dans son ordre de taille.
 *
 * Un produit sans référence — saisi à la main, jamais rattaché — n'a pas cet
 * ordre-là : il se range par son nom, à la suite de ceux qui en ont un. Le
 * mettre devant aurait fait passer une fiche d'essai en tête du rayon.
 *
 * ── Ce qui n'est PAS touché
 *
 * Aucune catégorie n'est réattribuée. Ranger, c'est déplacer les fiches, pas
 * décider à quel rayon elles appartiennent — cela, seule la boutique le sait,
 * et la caisse l'a déjà dit.
 */
final class CatalogueOrder
{
    /**
     * Les identifiants des produits, dans l'ordre où ils doivent se suivre.
     *
     * @param list<Product> $products
     * @param list<string>  $categoryOrder les rayons, dans l'ordre du magasin
     *
     * @return list<string>
     */
    public static function of(array $products, array $categoryOrder): array
    {
        $rangDuRayon = [];
        foreach (array_values($categoryOrder) as $rang => $nom) {
            $rangDuRayon[$nom] = $rang;
        }

        // Un rayon absent de la liste passe APRÈS tous les autres, et les
        // produits sans rayon ferment la marche. Les glisser au début aurait
        // mis en tête les fiches dont personne n'a encore décidé la place.
        $apres = count($rangDuRayon) + 1;

        $classes = [];

        foreach ($products as $produit) {
            $rayon = trim($produit->category);
            $reference = self::referenceNumerique($produit->recipeRef);

            $classes[] = [
                'cle' => [
                    $rayon === '' ? $apres + 1 : ($rangDuRayon[$rayon] ?? $apres),
                    // Sans référence, on passe derrière : 1 après 0.
                    $reference === null ? 1 : 0,
                    $reference ?? 0,
                    self::nom($produit),
                ],
                'id' => $produit->id,
            ];
        }

        usort($classes, static fn (array $a, array $b): int => $a['cle'] <=> $b['cle']);

        return array_map(static fn (array $c): string => $c['id'], $classes);
    }

    /**
     * La référence de la caisse, quand elle est un nombre.
     *
     * GoPOS numérote ses articles ; d'autres caisses emploient des codes
     * (« TIR-01 »). Comparer « TIR-01 » et « TIR-2 » comme des nombres n'aurait
     * aucun sens, et les comparer comme des textes remettrait l'alphabet aux
     * commandes. On ne se sert donc de la référence que lorsqu'elle est
     * franchement numérique.
     */
    private static function referenceNumerique(?string $reference): ?int
    {
        $texte = trim((string) $reference);

        return $texte !== '' && ctype_digit($texte) ? (int) $texte : null;
    }

    /** Le premier libellé renseigné, pour départager à égalité. */
    private static function nom(Product $product): string
    {
        foreach ($product->name as $libelle) {
            if (is_string($libelle) && trim($libelle) !== '') {
                return mb_strtolower(trim($libelle));
            }
        }

        return mb_strtolower($product->code);
    }
}
