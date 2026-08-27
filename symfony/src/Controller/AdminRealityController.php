<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\RealityCheck;
use Merisu\Inventory\Domain\RealityGauge;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Service\RealityService;
use Merisu\Inventory\Service\ReportScope;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Contrôle de réalité — le food cost réel face au théorique.
 *
 * ── Ce que l'écran répond
 *
 * « Ce qu'on a vendu aurait dû coûter tant en matière ; qu'a-t-on réellement
 * consommé ? » L'écart entre les deux est ce qui échappe à la recette : le
 * gâchis, la portion trop généreuse, la casse, le vol. La jauge en donne
 * l'ampleur ; le tableau jour / semaine / mois dit quand ça a dérivé.
 *
 * ── Manager compris, et scopé comme le reste
 *
 * Un manager doit voir la dérive de SA boutique — c'est là qu'il agit. L'accès
 * et le filtre sont donc écrits ensemble, comme sur Réseau : ouvrir sans
 * filtrer montrerait à un manager de Wrocław le gâchis de Varsovie.
 */
#[Route('/admin/realite')]
final class AdminRealityController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly RealityService $reality,
        private readonly ReportScope $scope,
        private readonly Store $store,
        private readonly InventoryService $inventory,
    ) {
    }

    #[Route('', name: 'admin_reality', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireManager();

        $aujourdhui = $this->inventory->today();

        // Le mois en cours par défaut : c'est la maille où l'on décide, et
        // celle où la roulée « semaine » et « mois » a de quoi se remplir.
        $to = BusinessDate::isValid((string) $request->query->get('to', '')) ? (string) $request->query->get('to') : $aujourdhui;
        $from = BusinessDate::isValid((string) $request->query->get('from', ''))
            ? (string) $request->query->get('from')
            : BusinessDate::firstOfMonth($to);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $tolerance = $this->store->settings()->deltaTolerance;

        $rapport = $this->reality->forPeriod(
            $from,
            $to,
            $this->scope->salesFilter($request),
            $this->scope->workstationIds($request),
            $tolerance,
        );

        /*
          La jauge de l'ensemble de la période.

          Les repères marquent les paliers de gravité RAPPORTÉS À L'ÉCHELLE :
          la tolérance et son triple, divisés par les 40 % du plein. Ainsi le
          vert, l'ambre et le rouge de la jauge tombent exactement là où le
          tableau change de couleur — une seule règle, lue deux fois.
        */
        $gauge = RealityGauge::build($rapport['overall']->fill, [
            min(1.0, $tolerance / RealityCheck::SCALE_MAX),
            min(1.0, $tolerance * 3.0 / RealityCheck::SCALE_MAX),
        ]);

        return $this->render('admin/reality.html.twig', $rapport + [
            'gauge' => $gauge,
            'scaleMax' => RealityCheck::SCALE_MAX,
            'scopeShops' => $this->scope->shops(),
            'scopeSelected' => $this->scope->selected($request),
            'scopeNoWorkstation' => $this->scope->workstationIds($request) === [],
            'tolerance' => $tolerance,
        ]);
    }
}
