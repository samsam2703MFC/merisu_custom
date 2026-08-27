<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\PosServiceInterface;
use Merisu\Inventory\Adapter\PosUnavailable;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\SalesBreakdown;
use Merisu\Inventory\Domain\SalesChart;
use Merisu\Inventory\Domain\SalesPeriod;
use Merisu\Inventory\Domain\SalesTrend;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Service\ReportScope;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface as SymfonyTranslator;

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
        private readonly SymfonyTranslator $i18n,
        private readonly ReportScope $scope,
    ) {
    }

    #[Route('', name: 'admin_sales', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        [$from, $to] = $this->interval($request);

        /*
          Le PÉRIMÈTRE, appliqué dès la lecture.

          Filtrer après coup aurait laissé les totaux, la tendance et les
          quatre découpes se calculer sur le réseau entier avant d'en montrer
          une part : les chiffres du bas n'auraient plus été la somme du haut,
          et rien ne l'aurait signalé.
        */
        $boutiques = $this->scope->salesFilter($request);

        $ventes = $this->store->sales($from, $to, $boutiques);

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
        $graphiques = [];
        $etiquettes = [];

        foreach (SalesPeriod::all() as $periode) {
            $cases = SalesBreakdown::of($ventes, $periode);
            $decoupes[$periode->value] = $cases;
            $graphiques[$periode->value] = self::chart($cases, $periode);

            /*
              Les libellés d'axe, construits ICI.

              Ils l'étaient dans une boucle Twig, avec `merge` — et `merge`
              RENUMÉROTE les clés numériques, comme `array_merge`. Les jours de
              semaine sont numérotés de 1 à 7 : la table repartait de zéro, et
              chaque colonne portait le nom du jour suivant. Lundi s'affichait
              « Mardi », et dimanche n'avait plus de nom du tout.
            */
            foreach ($cases as $case) {
                $etiquettes[$periode->value][$case->key] = $periode === SalesPeriod::Weekday
                    ? $this->i18n->trans('daysLong.' . $case->key)
                    : $case->key;
            }
        }

        /*
          La tendance : la même durée, juste avant.

          « 12 594 unités » ne dit ni si c'est bien ni si ça monte. Comparé aux
          six semaines précédentes, le chiffre devient une nouvelle. Comparer à
          un mois complet un mois entamé aurait annoncé une chute tous les
          premiers du mois, et l'on aurait cessé de regarder l'indicateur.
        */
        $avant = SalesTrend::previous($from, $to);
        // La comparaison porte sur le MÊME périmètre : une boutique comparée
        // au réseau entier annoncerait une chute de 70 % tous les jours.
        $ventesAvant = $this->store->sales($avant['from'], $avant['to'], $boutiques);

        $total = array_sum(array_map(static fn ($v): float => $v->quantity, $ventes));
        $recette = array_sum(array_map(static fn ($v): float => $v->revenue, $ventes));

        return $this->render('admin/sales.html.twig', [
            'from' => $from,
            'to' => $to,
            'configured' => $this->pos->isConfigured(),
            'periods' => SalesPeriod::all(),
            'breakdowns' => $decoupes,
            'charts' => $graphiques,
            'chartLabels' => $etiquettes,
            'products' => SalesBreakdown::byProduct($ventes),
            'known' => $parReference,
            'range' => $this->store->salesRange(),
            'scopeShops' => $this->scope->shops(),
            'scopeSelected' => $this->scope->selected($request),
            'total' => $total,
            'revenue' => $recette,
            'previous' => $avant,
            'trendQty' => SalesTrend::change(
                $total,
                array_sum(array_map(static fn ($v): float => $v->quantity, $ventesAvant)),
            ),
            'trendRevenue' => SalesTrend::change(
                $recette,
                array_sum(array_map(static fn ($v): float => $v->revenue, $ventesAvant)),
            ),
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

        /*
          La boutique VOYAGE avec la redirection.

          Elle ne change pas ce qu'on va chercher — la caisse est interrogée
          telle qu'elle l'a toujours été —, mais elle décide de l'écran sur
          lequel on retombe. Sans elle, actualiser depuis la vue d'une boutique
          renvoyait sur « toutes », et l'on croyait le filtre cassé.
        */
        $retour = ['from' => $from, 'to' => $to];
        $choisie = $this->scope->selected($request);

        if ($choisie !== null) {
            $retour['boutique'] = $choisie->code;
        }

        try {
            $ventes = $this->pos->sales($from, $to);
        } catch (PosUnavailable $e) {
            $this->addFlash('error', ['key' => $e->getMessage(), 'params' => []]);

            if ($e->detail !== '') {
                $this->addFlash('error', ['key' => 'admin.pos.hostSaid', 'params' => ['%detail%' => $e->detail]]);
            }

            return $this->redirectToRoute('admin_sales', $retour);
        }

        $ecrites = $this->store->saveSales($ventes);

        $this->store->audit($admin->id, $admin->role->value, 'SALES_FETCHED', null, null, [
            'from' => $from,
            'to' => $to,
            'rows' => $ecrites,
        ]);

        $this->addFlash('success', ['key' => 'admin.sales.fetched', 'params' => ['%count%' => $ecrites]]);

        return $this->redirectToRoute('admin_sales', $retour);
    }

    /**
     * Le dessin qui convient à cette découpe.
     *
     * ── Colonnes ou courbe : la densité décide
     *
     * Sept jours de semaine, six semaines, deux mois : des cases nommées, donc
     * des COLONNES, avec leur valeur sur le chapeau. Quarante-deux journées :
     * en colonnes, elles feraient huit pixels de large sur un téléphone — c'est
     * une COURBE qu'il faut, qui montre la forme de la période.
     *
     * ── Ce qu'on trace est TOUJOURS la moyenne par jour
     *
     * Une hauteur de barre est une affirmation. Comparer des totaux suppose des
     * cases de même durée, et elles ne le sont jamais : l'intervalle demandé
     * coupe la première et la dernière semaine, le mois en cours est entamé, et
     * les six dimanches d'une période n'ont pas le même compte que ses cinq
     * lundis.
     *
     * Le tracé mentait, et de façon vérifiable : sur juillet–août, la colonne
     * d'août écrasait celle de juillet — 7 223 contre 5 384 — alors que juillet
     * vendait DAVANTAGE chaque jour, 316,7 contre 288,9. Vingt-cinq journées
     * relevées contre dix-sept, et le graphique disait le contraire de son
     * propre tableau.
     *
     * La moyenne par jour, elle, se compare toujours. Les totaux restent dans
     * le tableau, à côté, où le nombre de journées est écrit.
     *
     * @param list<\Merisu\Inventory\Domain\SalesBucket> $buckets
     *
     * @return array{form: string, average: bool, ticks: list<array{value: float, y: float}>, columns: list<array<string, mixed>>, area: array<string, mixed>, peakKey: string}
     */
    private static function chart(array $buckets, SalesPeriod $period): array
    {
        $moyenne = true;
        $courbe = $period === SalesPeriod::Day;

        $sommet = 0.0;
        $cleDuSommet = '';

        foreach ($buckets as $case) {
            $valeur = $moyenne ? $case->averagePerDay() : $case->quantity;

            if ($valeur > $sommet) {
                $sommet = $valeur;
                $cleDuSommet = $case->key;
            }
        }

        // Les journées se lisent de gauche à droite, du plus ancien au plus
        // récent : une courbe qui remonte le temps se lit à l'envers. Les
        // tableaux, eux, gardent le plus récent en tête.
        $pourLaCourbe = array_reverse($buckets);

        return [
            'form' => $courbe ? 'area' : 'columns',
            'average' => $moyenne,
            'ticks' => SalesChart::ticks($sommet),
            'columns' => $courbe ? [] : SalesChart::columns($buckets, $moyenne),
            'area' => $courbe ? SalesChart::area($pourLaCourbe, $moyenne) : ['line' => '', 'area' => '', 'points' => []],
            'peakKey' => $cleDuSommet,
        ];
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
