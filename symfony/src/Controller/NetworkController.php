<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ShopRankingServiceInterface;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\MonthlyTarget;
use Merisu\Inventory\Domain\RankingMetric;
use Merisu\Inventory\Domain\ShopRanking;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Store\Store;
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
        private readonly Store $store,
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

        $metric = RankingMetric::tryFromLoose($request->query->get('metric')) ?? RankingMetric::TiramisuSold;

        $performances = $this->ranking->performances($from, $to);
        $currentShopId = $this->ranking->currentShopId();

        $paysCourant = null;
        foreach ($performances as $shop) {
            if ($shop->id === $currentShopId) {
                $paysCourant = $shop->country;
                break;
            }
        }

        // Le pays de la boutique du poste, et lui seul. Laisser choisir un
        // autre pays n'apprenait rien : le classement mondial le dit déjà, et
        // « Dans votre pays » vu depuis un pays qui n'est pas le sien n'a plus
        // de sens. Aucun paramètre d'URL, donc aucun état à retenir.
        $pays = $paysCourant;

        $national = $pays === null ? [] : ShopRanking::build($performances, $metric, $currentShopId, $pays);
        $mondial = ShopRanking::build($performances, $metric, $currentShopId);

        return $this->render('count/network.html.twig', [
            'from' => $from,
            'to' => $to,
            'metric' => $metric,
            'metrics' => RankingMetric::all(),
            'country' => $pays,
            'national' => $national,
            'worldwide' => $mondial,
            'myNational' => ShopRanking::positionOf($national, $currentShopId),
            'myWorldwide' => ShopRanking::positionOf($mondial, $currentShopId),
            'currentShopId' => $currentShopId,
            'gauge' => $this->jauge($to, $currentShopId),
        ]);
    }

    /**
     * Jauge tiramisu du mois en cours, pour la boutique du poste.
     *
     * Le mois CIVIL, et non les trente derniers jours du classement : un
     * objectif mensuel se juge du 1er au 31, sinon il ne se solde jamais.
     * D'où un second appel à l'adaptateur, sur une autre période.
     */
    private function jauge(string $today, ?string $currentShopId): ?MonthlyTarget
    {
        $objectif = $this->store->settings()->monthlyTiramisuTarget;

        // Aucun objectif fixé, ou aucune boutique identifiée : pas de jauge.
        // Une barre sans repère ne vaut pas mieux qu'une absence de barre.
        if ($objectif <= 0 || $currentShopId === null) {
            return null;
        }

        $vendus = 0;
        foreach ($this->ranking->performances(BusinessDate::firstOfMonth($today), $today) as $shop) {
            if ($shop->id === $currentShopId) {
                $vendus = $shop->tiramisuSold;
                break;
            }
        }

        $mois = BusinessDate::monthProgress($today);

        return MonthlyTarget::of($vendus, $objectif, $mois['day'], $mois['days']);
    }
}
