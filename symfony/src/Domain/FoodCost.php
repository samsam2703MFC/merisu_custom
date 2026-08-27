<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce que coûte une unité, et de quoi ce coût est fait.
 *
 * ── Matière et emballage séparés
 *
 * Ce ne sont pas les mêmes leviers. Le mascarpone se négocie au fournisseur ;
 * la barquette se change de modèle. Les additionner en un seul chiffre aurait
 * caché lequel des deux dérive, et c'est précisément la question qu'on pose à
 * un coût de revient.
 *
 * ── Les pertes sont ISOLÉES, pas fondues dans le coût
 *
 * Un ingrédient à 5 % de perte coûte 5 % de plus, mais on veut voir ces 5 %.
 * Fondus dans le total, ils deviennent invisibles et personne ne cherche plus
 * à les réduire — alors que c'est la seule part du coût qu'un geste d'atelier
 * peut faire baisser sans rien renégocier.
 *
 * ── Un coût INCOMPLET se dit
 *
 * Si un seul composant n'a pas de prix, le total est faux — et faux vers le
 * bas, ce qui est le pire sens : il a l'air bon. `complete` vaut alors false
 * et `missing` nomme les coupables, à charge pour l'écran de refuser d'afficher
 * un chiffre rassurant.
 */
final readonly class FoodCost
{
    /**
     * @param float        $materials coût des matières, pertes comprises
     * @param float        $packaging coût des emballages, pertes comprises
     * @param float        $waste     ce que les pertes ajoutent, déjà inclus ci-dessus
     * @param list<string> $missing   composants sans prix
     */
    public function __construct(
        public float $materials,
        public float $packaging,
        public float $waste,
        public bool $complete,
        public array $missing = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(0.0, 0.0, 0.0, true);
    }

    public function total(): float
    {
        return round($this->materials + $this->packaging, 4);
    }

    /**
     * La part des pertes dans le total, en %.
     *
     * Null quand il n'y a rien à rapporter : « 0 % de pertes sur un coût nul »
     * est une phrase vide qui occuperait une ligne.
     */
    public function wasteShare(): ?float
    {
        $total = $this->total();

        return $total > 0.0 ? round(($this->waste / $total) * 100, 1) : null;
    }

    /**
     * Le coût matière en % du prix de vente — le « food cost » proprement dit.
     *
     * Null sans prix de vente ou sans coût complet : un ratio calculé sur un
     * coût amputé donnerait un excellent chiffre pour une mauvaise raison, et
     * c'est exactement celui qu'on ne remettrait pas en question.
     */
    public function ratio(?float $sellingPrice): ?float
    {
        if (!$this->complete || $sellingPrice === null || $sellingPrice <= 0.0) {
            return null;
        }

        return round(($this->total() / $sellingPrice) * 100, 1);
    }

    public function plus(self $autre): self
    {
        return new self(
            $this->materials + $autre->materials,
            $this->packaging + $autre->packaging,
            $this->waste + $autre->waste,
            $this->complete && $autre->complete,
            array_values(array_unique([...$this->missing, ...$autre->missing])),
        );
    }

    /** Le même coût, multiplié — pour une quantité, ou une journée de ventes. */
    public function times(float $facteur): self
    {
        return new self(
            $this->materials * $facteur,
            $this->packaging * $facteur,
            $this->waste * $facteur,
            $this->complete,
            $this->missing,
        );
    }
}
