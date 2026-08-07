<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Catégorie de production — « Tiramisu », « Boissons », « Verrines »…
 *
 * Elle reste PORTÉE PAR LE PRODUIT, sous forme de texte : c'est ce champ qui
 * fait foi, et cette classe ne fait qu'en tenir la liste et l'ordre. Passer par
 * des identifiants aurait obligé à migrer les fiches existantes pour un gain
 * nul — une catégorie n'a rien d'autre qu'un nom.
 *
 * Ce que la liste apporte, et que le texte libre ne pouvait pas donner :
 *
 * · un ORDRE choisi. Sans elle, les groupes de l'écran de comptage se
 *   présentaient dans l'ordre où les produits avaient été créés, ce qui n'a
 *   aucun rapport avec l'ordre dans lequel on compte une boutique ;
 * · un renommage en UNE fois. Corriger « Tiramisu » en « Tiramisù » demandait
 *   de rouvrir chaque fiche, et en oublier une créait une catégorie fantôme
 *   qui doublait le groupe à l'écran.
 */
final readonly class ProductCategory
{
    public function __construct(
        public string $name,
        public int $sortOrder = 0,
        /** Produits actifs qui la portent. Calculé, jamais stocké. */
        public int $productCount = 0,
    ) {
    }

    /**
     * Nettoie un nom saisi.
     *
     * Les espaces de bord sont retirés et les espaces internes réduits : sans
     * cela, « Tiramisu » et « Tiramisu  » cohabiteraient comme deux catégories
     * distinctes, indiscernables à l'œil dans la liste.
     */
    public static function clean(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 64);
    }

    /**
     * Ordonne des groupes selon une liste de référence.
     *
     * Les catégories absentes de la liste viennent APRÈS celles qui y figurent,
     * dans leur ordre d'arrivée : une catégorie fraîchement saisie sur une
     * fiche produit doit apparaître à l'écran sans attendre qu'on soit passé
     * l'ordonner, sinon elle semblerait perdue.
     *
     * Le groupe sans catégorie ferme toujours la marche : c'est le fourre-tout,
     * pas une catégorie parmi les autres.
     *
     * @param list<array{category: string, products: list<Product>}> $groups
     * @param list<string>                                           $order
     *
     * @return list<array{category: string, products: list<Product>}>
     */
    public static function sortGroups(array $groups, array $order): array
    {
        $rang = array_flip($order);
        $connus = [];
        $inconnus = [];
        $sansCategorie = [];

        foreach ($groups as $groupe) {
            if ($groupe['category'] === '') {
                $sansCategorie[] = $groupe;
            } elseif (isset($rang[$groupe['category']])) {
                $connus[] = $groupe;
            } else {
                $inconnus[] = $groupe;
            }
        }

        usort(
            $connus,
            static fn (array $a, array $b): int => $rang[$a['category']] <=> $rang[$b['category']],
        );

        return array_merge($connus, $inconnus, $sansCategorie);
    }
}
