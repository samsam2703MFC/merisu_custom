<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Forme du contenant, pour un produit compté par contenant.
 *
 * Ne change RIEN au calcul : un seau à moitié plein et un bac à moitié plein
 * valent tous deux 0,5. C'est un repère visuel, et seulement cela.
 *
 * Il sert deux fois. En administration, l'icône dit d'un coup d'œil quels
 * produits acceptent une fraction, là où le mot « contenant » répété sur
 * chaque ligne ne se lisait plus. À la saisie, elle dit au vendeur ce qu'il
 * doit avoir en main : devant une bouteille, on ne compte pas comme devant
 * une cagette.
 *
 * La liste est volontairement courte. Six formes couvrent un laboratoire de
 * pâtisserie ; au-delà, le vendeur ne distinguerait plus les silhouettes à la
 * taille où elles s'affichent.
 */
enum ContainerType: string
{
    case Tub = 'TUB';
    case Bucket = 'BUCKET';
    case Bottle = 'BOTTLE';
    case Box = 'BOX';
    case Bag = 'BAG';
    case Jar = 'JAR';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Tub, self::Bucket, self::Bottle, self::Box, self::Bag, self::Jar];
    }

    /**
     * Repli sur le bac plutôt que sur null.
     *
     * Un produit compté par contenant a forcément une forme ; en l'absence
     * d'information — base installée avant ce réglage, valeur inconnue venue
     * d'un import — le bac est la forme la plus courante en laboratoire, et
     * une icône approximative reste plus lisible qu'une case vide.
     */
    public static function fromLoose(?string $value): self
    {
        return self::tryFromLoose($value) ?? self::Tub;
    }

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtoupper(trim($value)));
    }

    /** Nom de l'icône correspondante dans la macro `ui.icon`. */
    public function icon(): string
    {
        return 'container-' . strtolower($this->value);
    }
}
