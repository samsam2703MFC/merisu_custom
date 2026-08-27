<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\NetworkChart;
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
        /*
          Les MANAGERS entrent ici, et le filtre part dans le même geste.

          Ouvrir l'accès sans filtrer aurait livré à un manager les chiffres
          des boutiques voisines — exactement ce que le rôle est censé
          empêcher. Les deux ne se séparent pas : c'est pourquoi ils sont
          écrits l'un contre l'autre.
        */
        $pilote = $this->currentUser->requireManager();

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

        $toutes = $this->shops->all();

        /*
          Le PÉRIMÈTRE de la personne.

          Un admin voit tout. Un manager ne voit que ses boutiques — et un
          manager sans affectation n'en voit AUCUNE, plutôt que toutes : une
          liste vide veut dire « on ne lui en a pas encore donné », et la lire
          comme une permission ouvrirait le réseau à quiconque est promu avant
          qu'on ait rempli sa fiche.
        */
        $miennes = array_values(array_filter(
            $toutes,
            static fn (Shop $b): bool => $pilote->managesShop($b->id, $toutes),
        ));

        foreach ($miennes as $boutique) {
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
        /*
          Les relevés SANS boutique n'appartiennent à personne en particulier —
          ce sont ceux d'avant le réseau. Ils regardent l'administrateur, qui
          répond du total ; un manager n'a pas à en porter la charge, et les
          lui montrer gonflerait son écran d'un chiffre qu'il ne peut ni
          expliquer ni corriger.
        */
        $orphelines = $pilote->role->isAdmin() ? ($ventes[''] ?? null) : null;

        /*
          Les courbes.

          Le tableau des cartes dit OÙ ON EN EST ; il ne dit pas comment on y
          est arrivé. Deux boutiques au même total n'ont pas la même allure si
          l'une a vendu régulièrement et l'autre tout un week-end — et c'est
          l'allure qui annonce le mois prochain.

          Les ventes sont relues jour par jour, alors que `salesByShop` les
          rend déjà agrégées : c'est la seule lecture qui porte le temps, et
          la même requête sert les trois tracés.
        */
        $parBoutiqueEtJour = [];
        $nomDe = [];

        // Les courbes suivent le même périmètre que les cartes : un manager
        // qui verrait la courbe d'une boutique absente de ses cartes lirait
        // par le graphique ce que le tableau lui refuse.
        foreach ($miennes as $boutique) {
            $nomDe[$boutique->code] = $boutique->name;
            $parBoutiqueEtJour[$boutique->name] = [];
        }

        foreach ($this->store->sales($from, $to) as $vente) {
            // Un relevé d'avant le réseau n'a pas de boutique : il compte dans
            // le total du réseau, mais ne peut porter aucune courbe.
            $nom = $nomDe[$vente->shopCode] ?? null;

            if ($nom === null) {
                continue;
            }

            $parBoutiqueEtJour[$nom][$vente->date] = ($parBoutiqueEtJour[$nom][$vente->date] ?? 0.0) + $vente->quantity;
        }

        // Une boutique qui n'a rien vendu sur la période ne porte pas de
        // courbe plate à zéro : elle encombrerait la légende sans rien dire.
        $parBoutiqueEtJour = array_filter($parBoutiqueEtJour, static fn (array $j): bool => $j !== []);

        $objectifReseau = 0.0;
        foreach ($miennes as $boutique) {
            $objectifReseau += $boutique->monthlyTarget * $prorata;
        }

        $chart = NetworkChart::build(
            BusinessDate::range($from, $to),
            $parBoutiqueEtJour,
            $objectifReseau > 0.0 ? $objectifReseau : null,
        );

        return $this->render('admin/network.html.twig', [
            'chart' => $chart,
            'from' => $from,
            'to' => $to,
            'results' => ShopResult::rank($resultats),
            'orphans' => $orphelines,
            'prorata' => $prorata,
            /*
              Les totaux somment CE QU'ON MONTRE, pas le réseau.

              Ils partaient de `$ventes`, qui porte toutes les caisses : un
              manager aurait lu trois cartes et un total de neuf boutiques,
              sans que rien ne signale l'écart — le pire des deux, puisque le
              chiffre paraît cohérent avec la page.
            */
            'total' => array_sum(array_map(static fn (ShopResult $r): float => $r->quantity, $resultats)),
            'totalRevenue' => array_sum(array_map(static fn (ShopResult $r): float => $r->revenue, $resultats)),
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
