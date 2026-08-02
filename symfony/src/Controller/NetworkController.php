<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ShopRankingServiceInterface;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\RankingMetric;
use Merisu\Inventory\Domain\ShopRanking;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réseau — classement des boutiques, par pays et dans le monde.
 *
 * Écran de consultation : il ne produit aucune donnée et n'en modifie aucune.
 * Les chiffres viennent de la caisse via `ShopRankingServiceInterface` ; ce
 * module ne fait que les ordonner et les présenter.
 */
final class NetworkController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly InventoryService $inventory,
        private readonly ShopRankingServiceInterface $ranking,
    ) {
    }

    #[Route('/reseau', name: 'network', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $this->currentUser->requireConsultant();

        // Le mois écoulé par défaut : une journée serait trop courte pour
        // qu'un classement veuille dire quoi que ce soit.
        $to = $this->inventory->today();
        $from = $request->query->get('from') ?: BusinessDate::addDays($to, -29);
        $from = BusinessDate::isValid((string) $from) ? (string) $from : BusinessDate::addDays($to, -29);

        $metric = RankingMetric::tryFromLoose($request->query->get('metric')) ?? RankingMetric::Revenue;

        $performances = $this->ranking->performances($from, $to);
        $currentShopId = $this->ranking->currentShopId();

        $paysCourant = null;
        foreach ($performances as $shop) {
            if ($shop->id === $currentShopId) {
                $paysCourant = $shop->country;
                break;
            }
        }

        // Le pays demandé prime, sinon celui de la boutique courante : le
        // vendeur ouvre l'écran pour se situer chez lui d'abord.
        $pays = strtoupper(trim((string) $request->query->get('country', ''))) ?: $paysCourant;

        $national = $pays === null ? [] : ShopRanking::build($performances, $metric, $currentShopId, $pays);
        $mondial = ShopRanking::build($performances, $metric, $currentShopId);

        return $this->render('count/network.html.twig', [
            'from' => $from,
            'to' => $to,
            'metric' => $metric,
            'metrics' => RankingMetric::all(),
            'country' => $pays,
            'countries' => ShopRanking::countries($performances),
            'national' => $national,
            'worldwide' => $mondial,
            'myNational' => ShopRanking::positionOf($national, $currentShopId),
            'myWorldwide' => ShopRanking::positionOf($mondial, $currentShopId),
            'currentShopId' => $currentShopId,
        ]);
    }
}
