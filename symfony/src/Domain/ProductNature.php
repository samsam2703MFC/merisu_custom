<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qu'une ligne EST, et donc ce qu'on en fait.
 *
 * La boutique tient quatre choses très différentes sous le même toit, et les
 * confondre revient à demander à l'atelier de fabriquer du mascarpone ou à
 * compter des barquettes comme des desserts —
 *
 * · MATIÈRE PREMIÈRE. S'achète, se consomme. Mascarpone, café, cacao ;
 * · EMBALLAGE. S'achète aussi, mais n'entre dans aucune recette au sens
 *   culinaire : barquettes, couvercles, cuillères, sacs. Il se consomme
 *   pourtant à chaque vente, et c'est pour cela qu'il compte ;
 * · RECETTE. Se fabrique à l'atelier à partir de matières, et ne se vend pas
 *   telle quelle : crème mascarpone, sirop de café, biscuit imbibé. C'est la
 *   « sous-recette » (`/api/v1/subrecipes`) du système hôte ;
 * · PRODUIT EN VENTE. Ce qui passe en caisse (`/api/v1/products` chez l'hôte).
 *   Il s'ASSEMBLE : un emballage, une ou plusieurs recettes, parfois des
 *   matières directement.
 *
 * ── Deux familles, deux conséquences
 *
 * On ACHÈTE les deux premières, on FABRIQUE les deux dernières. Toute la
 * mécanique du module en découle :
 *
 * · ce qu'on achète a un seuil que l'atelier POSE — rien ne peut deviner
 *   combien de barquettes tenir d'avance jusqu'à la prochaine livraison ;
 * · ce qu'on fabrique a un minimum DÉDUIT de son écoulé et entre au plan de
 *   production. Une recette n'est pas vendue, mais elle est bel et bien
 *   consommée, et son écoulé dit donc quelque chose.
 *
 * ── Correspondance avec le système hôte
 *
 *   produit en vente  →  /api/v1/products
 *   recette           →  /api/v1/subrecipes
 *   matière première  →  /api/v1/materials, raw-materials
 *   emballage         →  /api/v1/packages, materials/{id}/packagings
 *
 * ⚠️ L'emballage est la seule correspondance INCERTAINE : chez l'hôte il
 * apparaît surtout comme un attribut d'une matière — son conditionnement — et
 * non comme une famille à part. La boutique, elle, compte ses barquettes comme
 * un stock. On garde donc la famille, et le pont se décidera au branchement.
 *
 * ── Le produit en vente est la valeur par défaut
 *
 * Une base installée avant cette distinction ne contient que des desserts qui
 * passent en caisse. Les basculer d'office en préparation les aurait retirés
 * de la vente ; les basculer en matière les aurait retirés de la production.
 */
enum ProductNature: string
{
    case Sale = 'SALE';
    case Recipe = 'RECIPE';
    case Raw = 'RAW';
    case Packaging = 'PACKAGING';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Sale, self::Recipe, self::Raw, self::Packaging];
    }

    /**
     * Lecture tolérante d'une valeur venue de la base ou d'un formulaire.
     *
     * `COMPOSED` est l'ancien nom du produit en vente, du temps où la nature
     * n'avait que deux valeurs. Les bases déjà en service le portent, et le
     * relire ici évite une migration de données pour un simple renommage.
     *
     * Repli sur le produit en vente : une valeur inconnue vient d'une base
     * ancienne ou d'une requête forgée, et le retirer de la vente ou de la
     * production sur ce seul motif ferait plus de dégâts que de le garder.
     */
    public static function fromLoose(mixed $value): self
    {
        $brut = is_scalar($value) ? strtoupper((string) $value) : '';

        return $brut === 'COMPOSED' ? self::Sale : (self::tryFrom($brut) ?? self::Sale);
    }

    /** Se fabrique-t-elle ? C'est ce qui décide de l'entrée au plan. */
    public function isProduced(): bool
    {
        return $this === self::Sale || $this === self::Recipe;
    }

    /** S'achète-t-elle ? Alors son seuil se pose à la main. */
    public function isPurchased(): bool
    {
        return !$this->isProduced();
    }

    public function isRaw(): bool
    {
        return $this === self::Raw;
    }

    /**
     * Peut-elle porter une nomenclature ?
     *
     * Ce qui se fabrique, et cela seul : décrire une barquette en termes de
     * barquettes n'aurait aucun sens.
     */
    public function canHaveRecipe(): bool
    {
        return $this->isProduced();
    }

    /**
     * Peut-elle ENTRER dans la nomenclature d'une autre ?
     *
     * Tout sauf le produit en vente : celui-ci est le sommet de l'assemblage,
     * et l'autoriser comme composant ouvrirait la porte aux cycles — un
     * tiramisu fait de tiramisu.
     */
    public function canBeComponent(): bool
    {
        return $this !== self::Sale;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Sale => 'nature-sale',
            self::Recipe => 'nature-recipe',
            self::Raw => 'nature-raw',
            self::Packaging => 'nature-packaging',
        };
    }
}
