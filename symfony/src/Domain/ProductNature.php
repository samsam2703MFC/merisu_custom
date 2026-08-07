<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Nature d'une ligne : matière première, ou composition.
 *
 * La boutique compte deux choses très différentes sous le même toit —
 *
 * · une COMPOSITION se fabrique. Le tiramisu part d'une recette, et c'est de
 *   lui que l'écran « À produire » calcule une quantité pour le lendemain ;
 * · une MATIÈRE PREMIÈRE s'achète et se consomme. Le mascarpone, le café, le
 *   cacao : on en compte le stock, mais on n'en « produit » jamais. Lui
 *   demander une quantité à produire n'a aucun sens, et le plan du lendemain
 *   demandait jusqu'ici de fabriquer douze kilos de mascarpone.
 *
 * La distinction porte donc une CONSÉQUENCE, pas seulement une étiquette :
 * seules les compositions entrent dans le plan de production. Les matières
 * premières restent comptées à l'ouverture comme à la clôture — c'est même
 * pour elles que le comptage compte le plus, puisqu'il déclenche la commande.
 *
 * La composition est la valeur par défaut : les huit emplacements d'origine
 * sont des tiramisus, et une base déjà en service ne doit rien avoir à
 * ressaisir pour continuer à produire comme la veille.
 */
enum ProductNature: string
{
    case Composed = 'COMPOSED';
    case Raw = 'RAW';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Composed, self::Raw];
    }

    /**
     * Lecture tolérante d'une valeur venue de la base ou d'un formulaire.
     *
     * Repli sur la composition plutôt que null : une valeur inconnue vient
     * d'une base ancienne ou d'une requête forgée, et retirer un produit du
     * plan de production sur ce seul motif le ferait manquer en rayon.
     */
    public static function fromLoose(mixed $value): self
    {
        return self::tryFrom(is_scalar($value) ? strtoupper((string) $value) : '') ?? self::Composed;
    }

    /** Se fabrique-t-elle ? C'est ce qui décide de l'entrée au plan. */
    public function isComposed(): bool
    {
        return $this === self::Composed;
    }

    public function isRaw(): bool
    {
        return $this === self::Raw;
    }

    /** L'autre valeur — ce que produit une bascule. */
    public function toggled(): self
    {
        return $this->isRaw() ? self::Composed : self::Raw;
    }

    /** Silhouette du sélecteur : une cuve pour la matière, un gâteau pour la composition. */
    public function icon(): string
    {
        return $this->isRaw() ? 'nature-raw' : 'nature-composed';
    }
}
