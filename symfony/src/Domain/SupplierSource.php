<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * D'où vient la ligne : de la centrale, ou d'un fournisseur libre.
 *
 * MERISU est un réseau de franchise, et c'est cette distinction qui décide de
 * ce qu'on fait d'un stock bas —
 *
 * · CENTRALE : la référence est celle du catalogue du réseau. La commande part
 *   au siège, aux conditions négociées pour tout le monde, et la référence est
 *   la même d'une boutique à l'autre. C'est le cas ordinaire ;
 * · LIBRE : la boutique achète où elle veut — le pain d'un boulanger voisin,
 *   les fruits d'un marché. La référence n'a alors de sens que localement, et
 *   le nom du fournisseur devient l'information utile.
 *
 * La centrale par défaut, et non « libre ». Dans un réseau, l'approvisionnement
 * centralisé est la règle et l'achat libre l'exception qu'une boutique déclare.
 * Poser « libre » d'office sur des fiches existantes aurait laissé croire que
 * chacune se débrouille, alors que personne n'a rien déclaré.
 */
enum SupplierSource: string
{
    case Central = 'CENTRAL';
    case Free = 'FREE';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Central, self::Free];
    }

    /**
     * Lecture tolérante d'une valeur venue de la base ou d'un formulaire.
     *
     * Repli sur la centrale : une base installée avant la distinction n'a rien
     * déclaré, et supposer un achat libre inventerait une décision que la
     * boutique n'a pas prise.
     */
    public static function fromLoose(mixed $value): self
    {
        return self::tryFrom(is_scalar($value) ? strtoupper((string) $value) : '') ?? self::Central;
    }

    public function isCentral(): bool
    {
        return $this === self::Central;
    }

    /**
     * Le nom du fournisseur sert-il à quelque chose ?
     *
     * En centrale, non : c'est le réseau, et le champ n'apprendrait rien. En
     * libre, c'est la seule façon de savoir chez qui recommander.
     */
    public function needsSupplierName(): bool
    {
        return $this === self::Free;
    }

    public function icon(): string
    {
        return $this->isCentral() ? 'supply-central' : 'supply-free';
    }
}
