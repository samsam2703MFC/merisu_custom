<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\PosServiceInterface;
use Merisu\Inventory\Adapter\PosUnavailable;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\SalesBreakdown;
use Merisu\Inventory\Domain\SalesPeriod;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Ventes — ce qui s'est vendu, et quand.
 *
 * ── Quatre vues, une seule source
 *
 * Tout part du relevé produit × jour gardé en base. La caisse saurait grouper
 * elle-même par mois ou par jour de semaine, mais quatre appels rendraient
 * quatre vérités : faits à quatre instants sur un jeu de commandes qui bouge,
 * leurs totaux ne s'additionneraient plus. Agrégé ici, le mois est par
 * construction la somme de ses jours.
 *
 * ── L'écran n'interroge PAS la caisse
 *
 * Le rapport met plusieurs secondes pour six semaines. Ouvrir l'onglet lit la
 * base ; c'est le bouton « Actualiser », ou la tâche `merisu:ventes`, qui va
 * chercher. Un affichage qui aurait appelé à chaque fois aurait rendu l'écran
 * inutilisable et rejoué le même rapport dix fois par matinée.
 */
#[Route('/admin/ventes')]
final class AdminSalesController extends AbstractController
{
    /** Six semaines : la fenêtre dont vit déjà la moyenne du stock minimum. */
    private const DEFAULT_DAYS = 42;

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly PosServiceInterface $pos,
        private readonly Store $store,
        private readonly InventoryService $inventory,
    ) {
    }

    #[Route('', name: 'admin_sales', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        [$from, $to] = $this->interval($request);

        $ventes = $this->store->sales($from, $to);

        // Le rattachement aux fiches locales se fait ICI, à la lecture : le
        // relevé garde la référence de la caisse, si bien qu'un article retiré
        // du catalogue conserve son historique au lieu de disparaître des
        // totaux du mois dernier.
        $parReference = [];
        foreach ($this->store->products() as $produit) {
            if (($produit->recipeRef ?? '') !== '') {
                $parReference[(string) $produit->recipeRef] = $produit;
            }
        }

        $decoupes = [];
        foreach (SalesPeriod::all() as $periode) {
            $decoupes[$periode->value] = SalesBreakdown::of($ventes, $periode);
        }

        return $this->render('admin/sales.html.twig', [
            'from' => $from,
            'to' => $to,
            'configured' => $this->pos->isConfigured(),
            'periods' => SalesPeriod::all(),
            'breakdowns' => $decoupes,
            'products' => SalesBreakdown::byProduct($ventes),
            'known' => $parReference,
            'range' => $this->store->salesRange(),
            'total' => array_sum(array_map(static fn ($v): float => $v->quantity, $ventes)),
            'revenue' => array_sum(array_map(static fn ($v): float => $v->revenue, $ventes)),
        ]);
    }

    /**
     * Va chercher le relevé chez la caisse.
     *
     * En POST : le rapport est lourd, et un lien se recharge d'un coup de F5.
     */
    #[Route('/actualiser', name: 'admin_sales_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        [$from, $to] = $this->interval($request);

        try {
            $ventes = $this->pos->sales($from, $to);
        } catch (PosUnavailable $e) {
            $this->addFlash('error', ['key' => $e->getMessage(), 'params' => []]);

            if ($e->detail !== '') {
                $this->addFlash('error', ['key' => 'admin.pos.hostSaid', 'params' => ['%detail%' => $e->detail]]);
            }

            return $this->redirectToRoute('admin_sales', ['from' => $from, 'to' => $to]);
        }

        $ecrites = $this->store->saveSales($ventes);

        $this->store->audit($admin->id, $admin->role->value, 'SALES_FETCHED', null, null, [
            'from' => $from,
            'to' => $to,
            'rows' => $ecrites,
        ]);

        $this->addFlash('success', ['key' => 'admin.sales.fetched', 'params' => ['%count%' => $ecrites]]);

        return $this->redirectToRoute('admin_sales', ['from' => $from, 'to' => $to]);
    }

    /**
     * L'intervalle demandé, ramené à quelque chose de sensé.
     *
     * Un intervalle à l'envers — début après la fin — rendrait un écran vide
     * sans rien dire. On le remet à l'endroit plutôt que de refuser : c'est une
     * faute de frappe, pas une intention.
     *
     * @return array{string, string}
     */
    private function interval(Request $request): array
    {
        $aujourdhui = $this->inventory->today();

        $to = (string) $request->query->get('to', $request->request->get('to', ''));
        $from = (string) $request->query->get('from', $request->request->get('from', ''));

        $to = BusinessDate::isValid($to) ? $to : $aujourdhui;
        $from = BusinessDate::isValid($from) ? $from : BusinessDate::addDays($to, -self::DEFAULT_DAYS + 1);

        return $from <= $to ? [$from, $to] : [$to, $from];
    }
}
