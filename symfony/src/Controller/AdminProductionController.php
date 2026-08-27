<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\ProductionForecast;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Service\ProductionPlanService;
use Merisu\Inventory\Service\ReportScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Gestion de production — combien produire, et de quoi.
 *
 * ── Ce que l'écran répond
 *
 * « Demain, combien de pièces, et qu'est-ce que je sors des bacs ? » La
 * réponse tient en une chaîne que l'écran montre plutôt que de la cacher :
 *
 *     base du jour de semaine × (1 + météo) × (1 + objectif) = pièces
 *
 * Chaque facteur est visible dans le détail d'une journée. Un plan de
 * production qu'on ne peut pas expliquer ne se discute pas : on l'applique ou
 * on l'ignore, et l'atelier choisit toujours de l'ignorer.
 *
 * ── L'objectif est PROPOSÉ, jamais imposé
 *
 * Il se déduit de l'objectif mensuel de la boutique — ce qu'il reste à faire,
 * rapporté à ce que la tendance donnerait. Mais il reste un champ : un
 * responsable qui sait qu'un pont arrive doit pouvoir corriger sans qu'on lui
 * demande de modifier l'objectif du mois.
 *
 * ── Manager compris, et scopé comme le reste
 *
 * C'est un écran d'exploitation : le manager de Wrocław y prépare SA semaine.
 * L'accès et le filtre sont donc écrits ensemble, comme sur Réseau.
 */
#[Route('/admin/production')]
final class AdminProductionController extends AbstractController
{
    /** Sept jours : la semaine que la prévision météo couvre. */
    private const DAYS_AHEAD = 7;

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly ProductionPlanService $plans,
        private readonly ReportScope $scope,
        private readonly InventoryService $inventory,
    ) {
    }

    #[Route('', name: 'admin_production', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireManager();

        $aujourdhui = $this->inventory->today();

        /*
          L'objectif de croissance, en pourcentage.

          Saisi s'il l'est, sinon zéro. Zéro veut dire « produis ce que la
          tendance annonce » — un défaut honnête, qui ne gonfle rien sans
          qu'on l'ait demandé. Borné : au-delà de +100 % on ne planifie plus,
          on rêve, et un nombre négatif sous −100 % rendrait des pièces
          négatives.
        */
        $objectif = $request->query->has('objectif')
            ? max(-90.0, min(100.0, (float) str_replace(
                ',', '.', (string) $request->query->get('objectif', '0'),
            )))
            : 0.0;

        $jours = max(1, min(14, (int) $request->query->get('jours', self::DAYS_AHEAD)));

        $plan = $this->plans->plan(
            $aujourdhui,
            $jours,
            $this->scope->salesFilter($request),
            $objectif,
        );

        return $this->render('admin/production.html.twig', $plan + [
            'today' => $aujourdhui,
            'targetPercent' => $objectif,
            'daysAhead' => $jours,
            'scopeShops' => $this->scope->shops(),
            'scopeSelected' => $this->scope->selected($request),
            'weeks' => ProductionForecast::WEEKS,
            'minObservations' => ProductionForecast::MIN_OBSERVATIONS,
        ]);
    }

    /**
     * Le BON DE PRODUCTION, à imprimer et à porter au labo.
     *
     * Une page à part, sans rail ni filtres : elle sort de l'imprimante et se
     * pose sur un plan de travail. Ce qu'on y met est ce qu'on y fait — les
     * pièces par produit, les matières à sortir — et rien de ce qui sert à
     * régler le plan, qui n'aide plus une fois la décision prise.
     */
    #[Route('/bon', name: 'admin_production_sheet', methods: ['GET'])]
    public function sheet(Request $request): Response
    {
        $this->currentUser->requireManager();

        $aujourdhui = $this->inventory->today();

        $date = BusinessDate::isValid((string) $request->query->get('date', ''))
            ? (string) $request->query->get('date')
            : $aujourdhui;

        $objectif = max(-90.0, min(100.0, (float) str_replace(
            ',', '.', (string) $request->query->get('objectif', '0'),
        )));

        // Un seul jour : le bon porte une journée d'atelier, pas une semaine.
        // Une feuille qui couvre sept jours se plie et se perd ; celle du jour
        // se punaise.
        $decalage = max(0, (int) round((strtotime($date) - strtotime($aujourdhui)) / 86400));

        $plan = $this->plans->plan(
            $aujourdhui,
            $decalage + 1,
            $this->scope->salesFilter($request),
            $objectif,
        );

        $jour = null;
        foreach ($plan['forecast']->days as $candidat) {
            if ($candidat->date === $date) {
                $jour = $candidat;
            }
        }

        return $this->render('admin/production_sheet.html.twig', [
            'day' => $jour,
            'date' => $date,
            'forecast' => $plan['forecast'],
            'pieces' => $jour?->pieces ?? 0,
            // Toutes les lignes qui font au moins une pièce : le bon porte la
            // journée entière, alors que l'écran n'en montre que la tête.
            'byProduct' => $jour === null ? [] : $plan['forecast']->topProducts($jour->pieces, 100),
            'names' => $plan['forecast']->names,
            'shop' => $this->scope->selected($request),
            'targetPercent' => $objectif,
        ]);
    }
}
