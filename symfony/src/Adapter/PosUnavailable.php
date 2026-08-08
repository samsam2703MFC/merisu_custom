<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * La caisse n'a pas répondu, ou a refusé.
 *
 * ── Le message ne suffit pas, le DÉTAIL compte
 *
 * « La caisse a refusé les identifiants » est vrai et inutilisable : on ne sait
 * ni à quelle étape — la demande de jeton ou l'appel de données — ni ce que la
 * caisse a répondu. Or `/oauth/token` n'est PAS décrit dans la spécification
 * GoPOS : elle en parle en prose, et « params » y désigne aussi bien un corps
 * de formulaire qu'une chaîne de requête. Sans le mot de la caisse, on ne peut
 * pas distinguer un identifiant erroné d'une requête mal formée.
 *
 * `detail` porte donc ce que la caisse a dit — son code HTTP, son `error` et
 * son `error_description`. Jamais le secret : il n'apparaît nulle part dans ce
 * qui remonte à l'écran.
 */
final class PosUnavailable extends \RuntimeException
{
    public function __construct(
        string $messageKey,
        /** Ce que la caisse a répondu, en clair. Vide si elle n'a rien dit. */
        public readonly string $detail = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($messageKey, 0, $previous);
    }
}
