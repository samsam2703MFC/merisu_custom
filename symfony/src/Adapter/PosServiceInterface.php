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
}
