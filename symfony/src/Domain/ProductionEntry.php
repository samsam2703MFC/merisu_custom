<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * « Fait » : une ligne du plan de production, produite et signée.
 *
 * Distincte de la ligne de plan, et volontairement dans SA propre table :
 * le plan se recalcule et se refige, l'atelier non. Une revalidation du
 * comptage du soir réécrit les quantités ; elle ne doit pas effacer le fait
 * que douze tiramisus sont sortis du frigo à 14 h 32.
 *
 * `qty` est la quantité PORTÉE PAR LE PLAN au moment de la signature, et non
 * une quantité ressaisie : ce qu'on atteste, c'est d'avoir fait la ligne telle
 * qu'elle était affichée. Elle est conservée parce qu'un plan refigé plus tard
 * changerait le chiffre sous la signature, et le compte rendu deviendrait faux
 * sans que personne ait rien touché.
 */
final readonly class ProductionEntry
{
    public function __construct(
        public string $forDate,
        public string $workstationId,
        public string $productId,
        public float $qty,
        /**
         * L'auteur du CODE, pas celui de la session.
         *
         * C'est toute la différence entre « qui était connecté » et « qui a
         * réellement produit ». Marco ouvre la session à 8 h, Claire monte les
         * verrines à 11 h : sans cette distinction, l'historique attribue le
         * travail de Claire à Marco.
         */
        public string $consultantId,
        public string $doneAt,
    ) {
    }
}
