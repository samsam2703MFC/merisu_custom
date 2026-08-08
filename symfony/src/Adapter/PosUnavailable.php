<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * La caisse n'a pas répondu, ou n'est pas configurée.
 *
 * Un seul type pour les quatre causes — identifiants absents, jeton refusé,
 * caisse injoignable, réponse illisible — parce que la conduite à tenir est la
 * même : ne RIEN importer, et le dire. Un import à moitié fait est pire qu'un
 * import qui n'a pas eu lieu : personne ne sait plus ce qui est à jour.
 *
 * Le message porte une clé de traduction (`admin.pos.*`) : il est montré à
 * l'écran, dans la langue de l'écran.
 */
final class PosUnavailable extends \RuntimeException
{
}
