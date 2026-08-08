<?php

declare(strict_types=1);

namespace Merisu\Inventory\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Le code PIN tel que les écrans l'envoient : six coupes, un champ par chiffre.
 *
 * Le format de saisie est une affaire d'écran — six cases se remplissent au
 * pouce sur une tablette, un champ unique non — mais le recollage n'a rien à
 * faire dans un gabarit, et encore moins dans trois contrôleurs. Quand la
 * connexion, la check-list et le plan de production posent la même question,
 * ils doivent la lire de la même façon : une divergence ici signifierait
 * qu'un code valide est accepté ici et refusé là.
 */
final class PinField
{
    /** Le nom du champ, partagé par tous les gabarits qui demandent un code. */
    public const NAME = 'secret';

    public static function read(Request $request): string
    {
        $valeur = $request->request->all()[self::NAME] ?? '';

        // Un envoi sans script arrive en tableau ; un client qui aurait recollé
        // les chiffres lui-même arrive en chaîne. Les deux sont acceptés : ce
        // qui compte est le code, pas la façon dont l'écran l'a découpé.
        if (\is_array($valeur)) {
            // `is_scalar` sur chaque coupe : une requête forgée peut y placer
            // un tableau, et la conversion en chaîne serait alors fatale — un
            // écran de connexion qui tombe en 500 sur une requête malformée
            // renseigne déjà celui qui la forge.
            $valeur = implode('', array_map(
                static fn (mixed $chiffre): string => is_scalar($chiffre) ? trim((string) $chiffre) : '',
                $valeur,
            ));
        }

        return is_scalar($valeur) ? trim((string) $valeur) : '';
    }
}
