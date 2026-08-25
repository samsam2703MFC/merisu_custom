<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\PosCategory;
use Merisu\Inventory\Domain\PosItem;

/**
 * ADAPTATEUR ENTRANT — la caisse GoPOS.
 *
 * Il LIT, il n'écrit jamais. Le catalogue de la boutique vit dans la caisse :
 * c'est là qu'un responsable crée un produit, le renomme ou le retire, parce
 * que c'est là qu'il passe en vente. Ce module en prend copie pour savoir quoi
 * compter — l'inverse aurait donné deux catalogues, et deux vérités.
 *
 * ── Trois valeurs, et rien d'autre
 *
 * `client_id`, `client_secret`, `organization_id`. Le jeton ne se configure
 * pas : il se demande à `/oauth/token` en `grant_type=organization`, il expire,
 * et l'implémentation le renouvelle d'elle-même. Un jeton posé à la main dans
 * un fichier de configuration aurait cessé de marcher un dimanche.
 */
interface PosServiceInterface
{
    /**
     * Les identifiants sont-ils en place ?
     *
     * Interrogé par les écrans AVANT de proposer un import : une action qui
     * échouera à tous les coups vaut moins que ne rien proposer, et
     * l'administration a de quoi expliquer ce qui manque.
     */
    public function isConfigured(): bool;

    /**
     * Les identifiants en vigueur, pour que l'écran dise D'OÙ ils viennent.
     *
     * Le secret est porté par l'objet — il en faut bien un pour appeler la
     * caisse — mais `PosCredentials::display()` est ce que le gabarit affiche,
     * et il ne le contient pas.
     */
    public function credentials(): \Merisu\Inventory\Domain\PosCredentials;

    /**
     * Vérifie que la caisse répond et que les identifiants sont acceptés.
     *
     * Rend le nom de l'organisation quand la caisse le donne, sinon son
     * identifiant : c'est ce qui permet à l'administrateur de constater qu'il
     * a branché LA BONNE boutique, ce qu'un simple « connexion réussie »
     * n'aurait pas dit.
     *
     * @throws PosUnavailable
     */
    public function ping(): string;

    /**
     * Les organisations que ces identifiants ouvrent.
     *
     * Une paire peut en porter PLUSIEURS : c'est ce qui permet de tenir tout un
     * réseau avec un seul secret, chaque boutique n'apportant que son numéro.
     * Personne ne peut le deviner d'avance — seule la caisse le dit.
     *
     * @return list<\Merisu\Inventory\Domain\PosOrganization>
     *
     * @throws PosUnavailable
     */
    public function organizations(): array;

    /**
     * Le même service, avec d'AUTRES identifiants.
     *
     * C'est ainsi qu'une boutique interroge SA caisse : on part des
     * identifiants du réseau, on y pose le numéro d'organisation de la
     * boutique, et l'on obtient un service qui ne parle qu'à elle. Sans cela,
     * il aurait fallu un service par boutique dans le conteneur — et les
     * boutiques se créent à l'écran, pas au déploiement.
     */
    public function withCredentials(\Merisu\Inventory\Domain\PosCredentials $credentials): self;

    /**
     * @return list<PosCategory>
     *
     * @throws PosUnavailable
     */
    public function categories(): array;

    /**
     * @return list<PosItem>
     *
     * @throws PosUnavailable
     */
    public function items(): array;

    /**
     * Les ventes, produit par produit et jour par jour.
     *
     * Le grain le plus fin, et lui seul : le jour de semaine, la semaine et le
     * mois s'en déduisent. Demander quatre groupements à la caisse aurait rendu
     * quatre vérités, faites à quatre instants sur un jeu de commandes qui
     * bouge, et dont les totaux ne s'additionneraient plus.
     *
     * @param string $from date de début, incluse (Y-m-d)
     * @param string $to   date de fin, incluse (Y-m-d)
     *
     * @return list<\Merisu\Inventory\Domain\PosSale>
     *
     * @throws PosUnavailable
     */
    public function sales(string $from, string $to, string $shopCode = ''): array;
}
