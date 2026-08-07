<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * L'hôte est injoignable, ou l'adaptateur n'est pas configuré.
 *
 * Distinguée d'une `RuntimeException` ordinaire parce que la conduite à tenir
 * n'est pas la même : ici on RÉESSAIE, là on abandonne. Un réseau coupé et un
 * corps de requête refusé se ressemblent dans les journaux, et les traiter de
 * la même façon condamnerait un comptage valide à cause d'une coupure de
 * trois minutes.
 */
final class SyncUnavailable extends \RuntimeException
{
}
