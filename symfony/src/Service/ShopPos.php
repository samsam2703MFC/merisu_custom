<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Adapter\PosServiceInterface;
use Merisu\Inventory\Domain\PosCredentials;
use Merisu\Inventory\Domain\Shop;

/**
 * La caisse D'UNE boutique.
 *
 * ── Par boutique, ou pour plusieurs : les deux montages tiennent
 *
 * Les identifiants GoPOS sont liés à une organisation au moment où on les
 * génère, ce qui laisse croire qu'il faut une paire par boutique. Mais
 * `/api/v3/me` rend une LISTE : une même paire peut porter des droits sur
 * plusieurs organisations. Deux montages sont donc possibles, et l'un n'exclut
 * pas l'autre dans le même réseau :
 *
 * · une paire PAR BOUTIQUE — saisie sur la fiche. Trois boutiques, trois
 *   secrets à faire tourner ;
 * · une paire POUR LE RÉSEAU — saisie dans Admin ▸ Caisse. Chaque boutique
 *   n'apporte alors que son numéro d'organisation, et il n'y a qu'un secret.
 *
 * ── La règle de résolution
 *
 * La fiche de la boutique l'emporte quand elle est complète : c'est le réglage
 * le plus précis, et celui qu'on vient de saisir. Sinon, les identifiants du
 * réseau, auxquels on impose le numéro d'organisation de la boutique.
 *
 * Ce numéro-là n'est JAMAIS emprunté au réseau : une boutique sans organisation
 * n'a pas de caisse. Reprendre celui du réglage général aurait fait lire à
 * Kraków le catalogue de Wrocław, sans qu'aucune erreur ne le signale — le
 * genre de silence qui se découvre au moment où l'on compare deux inventaires.
 */
final readonly class ShopPos
{
    public function __construct(private PosServiceInterface $pos)
    {
    }

    /**
     * La caisse de cette boutique, ou null si elle n'en a pas.
     *
     * Null plutôt qu'un service qui échouera : l'appelant peut alors le DIRE —
     * « cette boutique n'a pas de caisse » — au lieu de rapporter un refus
     * technique que personne ne sait interpréter.
     */
    public function forShop(Shop $shop): ?PosServiceInterface
    {
        $organisation = trim($shop->posOrganizationId);

        if ($organisation === '') {
            return null;
        }

        $reseau = $this->pos->credentials();

        // La paire de la boutique d'abord ; celle du réseau ensuite.
        $identifiant = trim($shop->posClientId) !== '' ? $shop->posClientId : $reseau->clientId;
        $secret = $shop->hasPosSecret() ? $shop->posClientSecret : $reseau->clientSecret;

        if (trim($identifiant) === '' || trim($secret) === '') {
            return null;
        }

        return $this->pos->withCredentials(new PosCredentials(
            $identifiant,
            $secret,
            $organisation,
            // L'adresse reste celle du réseau : GoPOS n'a qu'un hôte, et un
            // champ par boutique n'aurait servi qu'à le casser une fois sur
            // trois.
            $reseau->baseUrl,
            fromScreen: $shop->hasPosSecret(),
        ));
    }

    /**
     * D'où viennent les identifiants employés pour cette boutique.
     *
     * L'écran doit pouvoir le dire : quelqu'un qui change le secret du réseau
     * et voit une boutique continuer comme avant doit comprendre qu'elle a le
     * sien.
     */
    public function sourceFor(Shop $shop): string
    {
        if (trim($shop->posOrganizationId) === '') {
            return 'NONE';
        }

        return $shop->hasPosSecret() && trim($shop->posClientId) !== '' ? 'SHOP' : 'NETWORK';
    }
}
