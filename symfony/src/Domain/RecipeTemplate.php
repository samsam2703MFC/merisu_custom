<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Un modèle de composition : une recette écrite UNE fois, posée sur plusieurs
 * produits.
 *
 * ── Le calcul qui justifie ce détour
 *
 * Une gamme se décline en tailles, et la recette ne change qu'en quantité :
 * même crème, mêmes biscuits, même café, seules les grammes bougent. Une
 * boutique de neuf parfums en trois tailles fait vingt-sept fiches ; à quatre
 * matières chacune, cent huit champs à saisir, et cent huit occasions de se
 * tromper d'un chiffre. La règle, elle, tient en trois lignes de quatre.
 *
 * Écrire « Regular = 100 g de crème, 2 biscuits, 34 ml de café, 3 g de cacao »
 * une fois, puis la poser sur les neuf produits en Regular, c'est le même
 * travail que l'atelier fait de tête.
 *
 * ── Le rattachement se fait par le NOM, faute de mieux
 *
 * Rien dans la fiche produit ne dit « c'est une taille Regular » : la caisse
 * n'expose pas de variante, et les tailles vivent dans le libellé
 * (« Traditional Regular », « Berries Grande »). Le modèle porte donc un
 * fragment de texte, que l'administrateur saisit, et s'applique aux produits
 * dont un libellé le contient.
 *
 * C'est grossier, et c'est assumé : l'écran montre TOUJOURS la liste des
 * produits visés avant d'écrire. Un modèle qui attrape un produit de trop se
 * voit, là où une règle savante mais invisible aurait écrit en silence.
 *
 * ── Ce que le modèle ne fait pas
 *
 * Il ne remplace pas la composition : il y POSE ses lignes. Les matières que
 * le produit porte déjà et dont le modèle ne parle pas restent en place. Sans
 * cela, poser un modèle « taille » aurait effacé la crème propre à chaque
 * parfum, et l'on aurait dû ressaisir ce qu'on venait d'automatiser.
 */
final readonly class RecipeTemplate
{
    /** Au-delà, ce n'est plus un fragment de nom mais un libellé entier. */
    public const MATCH_MAX = 190;

    /**
     * @param array<string, float> $lines [materialId => quantité par unité]
     */
    public function __construct(
        public string $id,
        public string $name,
        /** Fragment cherché dans les libellés du produit. */
        public string $match,
        public array $lines = [],
        public int $sortOrder = 0,
    ) {
    }

    /**
     * Le fragment, nettoyé.
     *
     * Vide, il n'attrape RIEN — et surtout pas tout. Un fragment vide est
     * contenu dans n'importe quelle chaîne : le modèle se serait posé sur le
     * catalogue entier au premier clic, emballages compris.
     */
    public static function cleanMatch(string $match): string
    {
        return mb_substr(trim($match), 0, self::MATCH_MAX);
    }

    public function isUsable(): bool
    {
        return self::cleanMatch($this->match) !== '' && $this->lines !== [];
    }

    /**
     * Ce produit entre-t-il dans le modèle ?
     *
     * La comparaison ignore la casse et cherche dans TOUS les libellés : une
     * boutique dont l'écran est en polonais n'a pas forcément rempli le
     * français, et le fragment doit pouvoir viser la langue qu'on a sous les
     * yeux.
     *
     * Seul ce qui se FABRIQUE est visé. Poser une recette sur un sac en papier
     * n'aurait aucun sens, et l'admettre aurait fait du delta technique le
     * comparatif d'un emballage avec lui-même.
     */
    public function matches(Product $product): bool
    {
        $fragment = self::cleanMatch($this->match);

        if ($fragment === '' || !$product->nature->canHaveRecipe()) {
            return false;
        }

        $cherche = mb_strtolower($fragment);

        foreach ($product->name as $libelle) {
            if (is_string($libelle) && str_contains(mb_strtolower(trim($libelle)), $cherche)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les produits que ce modèle viserait.
     *
     * @param list<Product> $products
     *
     * @return list<Product>
     */
    public function targets(array $products): array
    {
        return array_values(array_filter($products, $this->matches(...)));
    }

    /**
     * La composition d'un produit, une fois le modèle posé dessus.
     *
     * Les lignes du modèle REMPLACENT celles de même matière, et laissent les
     * autres. « Remplacent » et non « s'ajoutent » : appliquer deux fois de
     * suite doit donner le même résultat qu'une fois, sinon un clic de trop
     * doublerait les quantités de tout le catalogue sans rien signaler.
     *
     * @param array<string, float> $existing [materialId => quantité par unité]
     *
     * @return array<string, float>
     */
    public function applyTo(array $existing): array
    {
        $sortie = $existing;

        foreach ($this->lines as $materialId => $quantite) {
            // Une ligne à zéro ne dit rien : c'est l'ABSENCE de ligne qui dit
            // « ce produit ne consomme pas cette matière ». On la retire donc,
            // ce qui donne au modèle le moyen d'annuler une ligne qu'il avait
            // posée.
            if ($quantite <= 0) {
                unset($sortie[$materialId]);

                continue;
            }

            $sortie[$materialId] = $quantite;
        }

        return $sortie;
    }
}
