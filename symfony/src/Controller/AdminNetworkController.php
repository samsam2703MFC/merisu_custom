<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\Shop;
use Merisu\Inventory\Domain\ShopResult;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Store\ShopStore;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Réseau — chaque boutique, et où elle en est de son objectif.
 *
 * ── Les chiffres viennent des RELEVÉS, pas d'une démonstration
 *
 * L'écran d'accueil montrait un classement issu de `LocalShopRankingService` :
 * des valeurs inventées, qui ne décrivaient aucune boutique. Celui-ci lit
 * `inv_sales_daily`, c'est-à-dire ce que les caisses ont réellement rendu,
 * boutique par boutique.
 *
 * ── L'objectif est ramené au prorata
 *
 * Il est mensuel ; la période affichée ne l'est pas forcément. Comparer dix
 * jours de ventes à un objectif de mois annoncerait un retard tous les 10 du
 * mois, et l'on cesserait de regarder l'indicateur.
 */
#[Route('/admin/reseau')]
final class AdminNetworkController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly Store $store,
        private readonly ShopStore $shops,
        private readonly InventoryService $inventory,
    ) {
    }

    #[Route('', name: 'admin_network', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        $aujourdhui = $this->inventory->today();

        // Le mois en cours par défaut : c'est la maille de l'objectif, et donc
        // la seule où la comparaison veut dire quelque chose sans explication.
        $to = BusinessDate::isValid((string) $request->query->get('to', '')) ? (string) $request->query->get('to') : $aujourdhui;
        $from = BusinessDate::isValid((string) $request->query->get('from', ''))
            ? (string) $request->query->get('from')
            : BusinessDate::firstOfMonth($to);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $ventes = $this->store->salesByShop($from, $to);
        $prorata = self::prorata($from, $to);

        $resultats = [];
        $couvert = 0;

        foreach ($this->shops->all() as $boutique) {
            $ligne = $ventes[$boutique->code] ?? ['quantity' => 0.0, 'revenue' => 0.0, 'days' => 0];
            $couvert += $ligne['days'] > 0 ? 1 : 0;

            $resultats[] = new ShopResult(
                $boutique,
                $ligne['quantity'],
                $ligne['revenue'],
                $ligne['days'],
                $boutique->monthlyTarget > 0 ? $boutique->monthlyTarget * $prorata : null,
            );
        }

        /*
          Les relevés SANS boutique.

          Ceux d'avant le réseau, ou d'une installation à caisse unique. Les
          taire aurait fait un total de réseau inférieur à la somme des ventes,
          sans que rien ne l'explique.
        */
        $orphelines = $ventes[''] ?? null;

        return $this->render('admin/network.html.twig', [
            'from' => $from,
            'to' => $to,
            'results' => ShopResult::rank($resultats),
            'orphans' => $orphelines,
            'prorata' => $prorata,
            'total' => array_sum(array_map(static fn (array $v): float => $v['quantity'], $ventes)),
            'totalRevenue' => array_sum(array_map(static fn (array $v): float => $v['revenue'], $ventes)),
            'covered' => $couvert,
        ]);
    }

    /**
     * La part du mois que la période représente.
     *
     * Bornée à 1 : une période plus longue qu'un mois ne doit pas gonfler
     * l'objectif au-delà de ce qui a été fixé — on ne demande pas deux mois de
     * ventes pour un objectif mensuel.
     */
    private static function prorata(string $from, string $to): float
    {
        $jours = count(BusinessDate::range($from, $to));
        $dansLeMois = (int) (new \DateTimeImmutable($to))->format('t');

        return $dansLeMois > 0 ? min(1.0, $jours / $dansLeMois) : 1.0;
    }
}
