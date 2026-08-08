<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * L'avancement de l'atelier : combien de lignes faites sur combien à faire.
 *
 * ── Ce qui compte, et ce qui ne compte pas
 *
 * Seules les lignes à produire entrent au dénominateur. Un plan de vingt
 * lignes dont quinze sont à zéro — le stock du soir suffisait — n'est pas
 * « 5/20 fait » : il n'y a que cinq choses à faire, et afficher 25 % là où
 * l'atelier a tout terminé décourage pour rien.
 *
 * Une ligne sans seuil (`missingThreshold`) compte quand même : sa quantité
 * est peut-être fausse, mais elle est affichée, et quelqu'un devra la traiter.
 */
final readonly class ProductionProgress
{
    private function __construct(
        public int $done,
        public int $total,
        public int $percent,
    ) {
    }

    /**
     * @param list<ProductionPlanRow>          $lines
     * @param array<string, ProductionEntry>   $entries indexées par produit
     */
    public static function of(array $lines, array $entries): self
    {
        $total = 0;
        $done = 0;

        foreach ($lines as $line) {
            if (!self::isActionable($line)) {
                continue;
            }

            ++$total;

            if (isset($entries[$line->productId])) {
                ++$done;
            }
        }

        // Zéro sur zéro n'est pas 0 % : il n'y a rien à faire, donc tout est
        // fait. Le contraire aurait affiché une barre vide sur un plan vide.
        $percent = $total === 0 ? 100 : (int) round($done * 100 / $total);

        return new self($done, $total, $percent);
    }

    /**
     * Une ligne sur laquelle l'atelier a quelque chose à faire.
     *
     * Publique parce que l'écran pose la MÊME question, ligne par ligne, pour
     * décider s'il affiche une case à cocher : une ligne à zéro n'en a pas.
     * Deux définitions du « à faire » auraient fini par diverger, et la barre
     * n'aurait plus décrit les cases affichées.
     */
    public static function isActionable(ProductionPlanRow $line): bool
    {
        return $line->qtyToProduce > 0 || $line->missingThreshold;
    }

    /** Tout ce qu'il y avait à faire est fait. */
    public function isComplete(): bool
    {
        return $this->done >= $this->total;
    }

    public function left(): int
    {
        return max(0, $this->total - $this->done);
    }
}
