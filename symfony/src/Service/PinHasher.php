<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

/**
 * Empreinte d'un code PIN, pour ne jamais le stocker en clair.
 *
 * ── Pourquoi un HMAC et non bcrypt ──────────────────────────────────────────
 *
 * La connexion se fait au code PIN SEUL : l'application ne sait pas qui se
 * présente avant d'avoir reconnu le code. Avec bcrypt, il faudrait vérifier le
 * code contre chaque compte l'un après l'autre — une seconde par compte, et
 * l'écran de connexion deviendrait inutilisable dès la dizaine de vendeurs.
 *
 * Le HMAC donne une empreinte déterministe : on la calcule une fois et on
 * interroge la base par égalité, en temps constant quel que soit le nombre de
 * comptes, et sans révéler lequel a été touché.
 *
 * Ce que cela protège, et ce que cela ne protège pas :
 *
 * · une base dérobée SANS la clé ne livre aucun code — c'est le cas qu'on
 *   traite, et c'est le plus courant (sauvegarde égarée, accès en lecture) ;
 * · une base dérobée AVEC la clé livre les codes : six chiffres se parcourent
 *   en entier en une seconde. Aucun algorithme n'y changerait grand-chose à
 *   cette longueur — c'est la limite du code PIN lui-même, pas du hachage.
 *
 * La vraie défense contre le forçage reste en amont : le limiteur de tentatives
 * de `SecurityController`. La clé, elle, vit dans APP_SECRET, hors de la base.
 */
final readonly class PinHasher
{
    public function __construct(private string $appSecret)
    {
    }

    /**
     * Empreinte du code, ou null si le code est vide.
     *
     * Null et non une chaîne vide : une empreinte vide en base serait partagée
     * par tous les comptes sans code, et l'index d'unicité les refuserait à
     * partir du deuxième.
     */
    public function hash(string $pin): ?string
    {
        $pin = trim($pin);

        return $pin === '' ? null : hash_hmac('sha256', $pin, $this->appSecret);
    }

    /**
     * Le code est-il de la forme attendue ?
     *
     * Six chiffres exactement : c'est ce que le pavé de connexion accepte, et
     * un code plus court y resterait insaisissable.
     */
    public static function isWellFormed(string $pin): bool
    {
        return preg_match('/^\d{6}$/', trim($pin)) === 1;
    }
}
