<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Procedure;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Store\ProcedureStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le manuel opératoire, côté POSTE.
 *
 * ── Consulté debout, en pleine panne
 *
 * C'est le contexte qui commande la forme. On l'ouvre une main occupée, devant
 * une machine qui ne marche pas, avec un client qui attend : les procédures
 * arrivent dépliées, regroupées par rayon, sans écran intermédiaire à
 * traverser. Une liste de titres à cliquer aurait ajouté un geste au pire
 * moment.
 *
 * ── Le PROBLÈME est mis en avant, pas le titre
 *
 * On ne cherche pas « procédure 14 », on cherche ce qu'on a sous les yeux. La
 * description de la situation est donc lisible avant la marche à suivre, et
 * c'est elle qui permet de reconnaître son cas.
 *
 * ── Ouvert à TOUS ceux qui sont connectés
 *
 * Pas de tuile à ouvrir en administration : le manuel ne modifie rien, il ne
 * révèle aucun chiffre, et la personne qui en a besoin est justement celle à
 * qui l'on n'a peut-être pas pensé. Le lui fermer ferait perdre plus que le
 * lui ouvrir.
 */
final class ProcedureController extends AbstractController
{
    public function __construct(
        private readonly ProcedureStore $procedures,
        private readonly CurrentUser $currentUser,
    ) {
    }

    #[Route('/manuel', name: 'procedures', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireConsultant();

        $locale = Locale::tryFromLoose($request->getLocale()) ?? Locale::Fr;

        /*
          Seules les procédures ACTIVES et UTILISABLES.

          Un titre sans marche à suivre s'ouvrirait sur une page blanche, en
          pleine panne — le moment exact où le manuel doit tenir sa promesse.
          Une procédure incomplète reste visible en administration, signalée,
          pour qu'on la finisse ; elle n'atteint pas le poste.
        */
        $utiles = array_values(array_filter(
            $this->procedures->all(activeOnly: true),
            static fn (Procedure $p): bool => $p->isUsable($locale),
        ));

        // Regroupées par rayon, dans l'ordre où le store les rend. Les
        // procédures sans rayon forment un groupe à part, en dernier : les
        // ranger sous une étiquette inventée aurait menti sur leur classement.
        $parRayon = [];
        foreach ($utiles as $procedure) {
            $parRayon[$procedure->topic][] = $procedure;
        }

        $sansRayon = $parRayon[''] ?? [];
        unset($parRayon['']);

        if ($sansRayon !== []) {
            $parRayon[''] = $sansRayon;
        }

        return $this->render('count/procedures.html.twig', [
            'byTopic' => $parRayon,
            'locale' => $locale,
            'total' => \count($utiles),
        ]);
    }
}
