<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Combien d'étiquettes tirer, et pour quelles lignes.
 *
 * UNE ÉTIQUETTE PAR PIÈCE. Trente crèmes à produire, trente étiquettes : ce
 * sont trente pots qui partiront en vitrine, et chacun porte ses allergènes et
 * sa date limite. Une seule étiquette pour la fournée entière obligeait
 * l'atelier à la recopier vingt-neuf fois à la main — ou, plus souvent, à
 * n'en coller aucune.
 *
 * ── L'arrondi va vers le HAUT
 *
 * Une ligne de 2,5 bacs donne trois étiquettes. On ne colle pas une demi-
 * étiquette, et il vaut mieux en jeter une que d'en manquer une : le bac non
 * étiqueté est celui qui ressort du frigo sans qu'on sache de quand il date.
 */
final readonly class LabelSheet
{
    /**
     * Plafond d'une planche.
     *
     * Un garde-fou contre la saisie fautive, pas une limite de métier : mille
     * étiquettes, c'est déjà une trentaine de feuilles A4. Au-delà, c'est
     * presque toujours un seuil tapé avec un zéro de trop — et le plafond
     * évite qu'un navigateur reste bloqué sur une page de cent mille cadres.
     *
     * Ce qui dépasse n'est PAS perdu en silence : la planche annonce ce
     * qu'elle n'a pas imprimé.
     */
    public const MAX = 1000;

    private function __construct(
        /** @var array<string, int> produit => nombre d'étiquettes tirées */
        public array $copies,
        public int $total,
        /** Étiquettes qu'il aurait fallu tirer, et que le plafond a écartées. */
        public int $dropped,
    ) {
    }

    /** @param list<ProductionPlanRow> $lines */
    public static function of(array $lines): self
    {
        $copies = [];
        $total = 0;
        $ecartees = 0;

        foreach ($lines as $line) {
            $voulues = self::copiesFor($line->qtyToProduce);
            if ($voulues === 0) {
                continue;
            }

            // La place restante, ligne après ligne : on remplit dans l'ordre
            // de la planche plutôt que de réduire toutes les lignes d'un
            // même pourcentage — une ligne entière imprimée vaut mieux que
            // dix lignes amputées.
            $tirees = min($voulues, max(0, self::MAX - $total));

            if ($tirees > 0) {
                $copies[$line->productId] = $tirees;
                $total += $tirees;
            }

            $ecartees += $voulues - $tirees;
        }

        return new self($copies, $total, $ecartees);
    }

    /**
     * Le nombre d'étiquettes d'une quantité.
     *
     * Zéro pour tout ce qui ne se produit pas : une étiquette « 0 » gaspille
     * du papier et embrouille le plan de travail.
     */
    public static function copiesFor(float $qty): int
    {
        if (!is_finite($qty) || $qty <= 0) {
            return 0;
        }

        return (int) ceil($qty);
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /** Le plafond a-t-il retenu quelque chose ? */
    public function isTruncated(): bool
    {
        return $this->dropped > 0;
    }
}
