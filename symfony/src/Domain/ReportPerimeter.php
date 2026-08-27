<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

use Merisu\Inventory\Adapter\Consultant;
use Merisu\Inventory\Adapter\Workstation;

/**
 * Sur quelles boutiques un rapport porte, pour la personne qui le lit.
 *
 * ── Pourquoi cette règle vit dans le domaine
 *
 * C'est un CONTRÔLE D'ACCÈS. La boutique arrive par l'URL : sans validation,
 * `?boutique=WARSZAWA` ouvrait à un manager de Wrocław les chiffres de
 * Varsovie — sans effraction, en tapant dans la barre d'adresse. Une règle de
 * cette portée ne peut pas vivre dans un contrôleur, où elle ne serait
 * vérifiable qu'en ouvrant un navigateur ; ici elle se prouve.
 *
 * ── Trois clés pour la même boutique
 *
 * Les ventes portent le CODE (`inv_sales_daily.shop_code`), les objectifs
 * l'IDENTIFIANT (`inv_shop_target.shop_id`), et les comptages ne portent ni
 * l'un ni l'autre : ils portent un POSTE, rattaché à une boutique. Traduire le
 * choix dans la clé de chaque table se fait ici et non dans chaque rapport —
 * c'est là que se glisse l'erreur silencieuse d'un code comparé à un
 * identifiant, qui ne remonte jamais aucune ligne et ne lève jamais rien.
 */
final readonly class ReportPerimeter
{
    /**
     * `$person` à `null` : PERSONNE, donc aucune boutique.
     *
     * Les rapports exigent tous une session ; ce cas ne devrait pas se
     * produire. Mais le repli d'une règle d'accès doit FERMER et non ouvrir —
     * sans quoi le jour où l'un d'eux oublie son contrôle, c'est le réseau
     * entier qui s'affiche à un inconnu.
     *
     * @param list<Shop> $allShops toutes les boutiques du réseau
     */
    public function __construct(
        private ?Consultant $person,
        private array $allShops,
    ) {
    }

    /**
     * Les boutiques que cette personne peut lire.
     *
     * Un administrateur les a toutes. Un manager n'a que les siennes — et un
     * manager SANS affectation n'en a AUCUNE, plutôt que toutes : la liste
     * vide veut dire « on ne lui en a pas encore donné ».
     *
     * @return list<Shop>
     */
    public function shops(): array
    {
        if ($this->person === null) {
            return [];
        }

        return array_values(array_filter(
            $this->allShops,
            fn (Shop $b): bool => $this->person->managesShop($b->id, $this->allShops),
        ));
    }

    /**
     * La boutique demandée, ou `null` pour « toutes celles que je pilote ».
     *
     * Une demande HORS PÉRIMÈTRE est ramenée au périmètre plutôt que refusée :
     * un lien partagé entre deux managers ne doit pas afficher une erreur mais
     * ce que celui qui l'ouvre a le droit de voir.
     */
    public function resolve(string $requested): ?Shop
    {
        $demande = trim($requested);

        if ($demande === '') {
            return null;
        }

        foreach ($this->shops() as $boutique) {
            // L'identifiant COMME le code : les deux circulent déjà dans les
            // URL des rapports, et casser les liens en cours n'aurait servi
            // qu'à faire croire que les écrans s'étaient vidés.
            if ($boutique->id === $demande || $boutique->code === $demande) {
                return $boutique;
            }
        }

        return null;
    }

    /**
     * Les CODES à interroger — la clé des ventes.
     *
     * @return list<string>
     */
    public function codes(?Shop $selected): array
    {
        if ($selected !== null) {
            return [$selected->code];
        }

        return array_values(array_map(static fn (Shop $b): string => $b->code, $this->shops()));
    }

    /**
     * Faut-il restreindre les ventes, ou tout laisser passer ?
     *
     * Un administrateur qui n'a rien choisi voit TOUT, y compris les remontées
     * d'avant le réseau qui ne portent aucun code : les taire ferait un total
     * inférieur à la somme des ventes sans que rien ne l'explique. Un manager
     * reste restreint même sans choix — c'est ce qui empêche « toutes » d'être
     * une porte ouverte.
     */
    public function filtersSales(?Shop $selected): bool
    {
        return $selected !== null || $this->person?->role->isAdmin() !== true;
    }

    /**
     * Les POSTES du périmètre — la clé des comptages, du journal et des PAR.
     *
     * Ces tables ne portent pas de boutique : elles portent un poste, et c'est
     * `inv_workstation.shop_id` qui fait le pont. Un poste SANS boutique reste
     * visible pour l'administrateur — il existait avant le réseau, et le
     * cacher ferait disparaître son historique — mais jamais pour un manager,
     * qui n'a aucun moyen de dire à qui il appartient.
     *
     * @param list<Workstation> $workstations
     *
     * @return list<string>
     */
    public function workstationIds(?Shop $selected, array $workstations): array
    {
        $miennes = [];
        foreach ($this->shops() as $boutique) {
            $miennes[$boutique->id] = true;
        }

        $estAdmin = $this->person?->role->isAdmin() === true;
        $retenus = [];

        foreach ($workstations as $poste) {
            if ($selected !== null) {
                if ($poste->shopId === $selected->id) {
                    $retenus[] = $poste->id;
                }

                continue;
            }

            if ($poste->shopId === '' ? $estAdmin : isset($miennes[$poste->shopId])) {
                $retenus[] = $poste->id;
            }
        }

        return $retenus;
    }
}
