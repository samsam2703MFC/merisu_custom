<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Adapter\ShopRankingServiceInterface;
use Merisu\Inventory\Domain\MetricTarget;
use Merisu\Inventory\Domain\ShopMetric;
use Merisu\Inventory\Domain\TargetMonth;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Store\HrStore;
use Merisu\Inventory\Store\ShopStore;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Objectifs — les seuils d'une boutique, mois par mois.
 *
 * ── Trois seuils, et non un chiffre
 *
 * C'est la forme qu'attend l'hôte (`threshold_1`, `threshold_2`,
 * `threshold_3`), et c'est aussi celle qui rend un objectif lisible : le
 * premier est le minimum acceptable, le troisième l'excellence. Un objectif
 * unique ne dit que « atteint » ou « raté », là où trois disent de combien.
 *
 * ── Le catalogue d'indicateurs se tient ici
 *
 * Aucun indicateur n'est écrit dans le code (§2) : le réseau change les siens
 * plus souvent qu'on ne redéploie. Ils vivent sur le même écran que les
 * seuils — les séparer aurait obligé à faire un aller-retour pour créer la
 * ligne qu'on veut justement remplir.
 */
#[Route('/admin/objectifs')]
final class AdminTargetController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly HrStore $hr,
        private readonly Store $store,
        private readonly InventoryService $inventory,
        private readonly ShopRankingServiceInterface $ranking,
        private readonly ConsultantServiceInterface $consultants,
        private readonly ShopStore $shops,
    ) {
    }

    #[Route('', name: 'admin_targets', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        $mois = $this->moisDemande($request);
        $boutique = $this->boutiqueDemandee($request);

        return $this->render('admin/targets.html.twig', [
            'metrics' => $this->hr->metrics(),
            'targets' => $this->hr->targets($boutique, $mois),
            // Le mois PRÉCÉDENT sert au report : c'est le geste le plus
            // fréquent en début de mois, et le retaper à la main sur douze
            // indicateurs est le meilleur moyen d'en fausser un.
            'previous' => $this->hr->targets($boutique, $mois->previous()),
            'month' => $mois,
            'monthKey' => $mois->key(),
            'previousKey' => $mois->previous()->key(),
            'nextKey' => $mois->next()->key(),
            'shopId' => $boutique,
            'shops' => $this->boutiques(),
        ]);
    }

    /** Enregistre toute la grille d'un mois : un envoi pour l'écran entier. */
    #[Route('', name: 'admin_targets_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $mois = $this->moisDemande($request);
        $boutique = $this->boutiqueDemandee($request);

        /** @var array<string, array<string, mixed>> $lignes */
        $lignes = $request->request->all('target');
        $enregistres = 0;
        $supprimes = 0;

        foreach ($this->hr->metrics() as $indicateur) {
            $champs = $lignes[$indicateur->key] ?? [];

            $brut = array_map(
                static fn (string $c): string => trim((string) ($champs[$c] ?? '')),
                ['t1', 't2', 't3'],
            );

            // Les trois cases vides = pas d'objectif ce mois-ci. C'est le
            // geste naturel pour en retirer un, et il évite un bouton
            // « supprimer » par ligne sur une grille qui en compte déjà douze.
            if (implode('', $brut) === '') {
                $this->hr->deleteTarget($boutique, $mois, $indicateur->key);
                ++$supprimes;

                continue;
            }

            $cible = MetricTarget::of(
                $indicateur->key,
                ...array_map(static fn (string $v): float => (float) str_replace(',', '.', $v ?: '0'), $brut),
            );

            if ($cible === null) {
                continue;
            }

            $this->hr->saveTarget($boutique, $mois, $cible, $admin->id);
            ++$enregistres;
        }

        $this->store->audit($admin->id, $admin->role->value, 'SHOP_TARGETS_UPDATE', null, null, [
            'shopId' => $boutique,
            'month' => $mois->key(),
            'saved' => $enregistres,
            'cleared' => $supprimes,
        ]);

        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_targets', ['mois' => $mois->key(), 'boutique' => $boutique]);
    }

    /**
     * Reporte le mois précédent sur celui-ci.
     *
     * N'écrase QUE les lignes vides : un objectif déjà posé pour ce mois-ci a
     * été posé exprès, et le report est une aide au démarrage, pas une remise
     * à zéro.
     */
    #[Route('/reporter', name: 'admin_targets_copy', methods: ['POST'])]
    public function copy(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $mois = $this->moisDemande($request);
        $boutique = $this->boutiqueDemandee($request);

        $existants = $this->hr->targets($boutique, $mois);
        $reportes = 0;

        foreach ($this->hr->targets($boutique, $mois->previous()) as $cle => $cible) {
            if (isset($existants[$cle])) {
                continue;
            }

            $this->hr->saveTarget($boutique, $mois, $cible, $admin->id);
            ++$reportes;
        }

        $this->store->audit($admin->id, $admin->role->value, 'SHOP_TARGETS_COPIED', null, null, [
            'shopId' => $boutique,
            'from' => $mois->previous()->key(),
            'to' => $mois->key(),
            'copied' => $reportes,
        ]);

        $this->addFlash('success', $reportes > 0 ? 'admin.targets.copied' : 'admin.targets.nothingToCopy');

        return $this->redirectToRoute('admin_targets', ['mois' => $mois->key(), 'boutique' => $boutique]);
    }

    // ── Catalogue d'indicateurs ─────────────────────────────────────────────

    #[Route('/indicateur', name: 'admin_targets_metric_add', methods: ['POST'])]
    public function addMetric(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $libelle = mb_substr(trim((string) $request->request->get('label', '')), 0, 120);
        // La clé se déduit du libellé quand elle n'est pas donnée : on ne
        // demande pas à un responsable de boutique d'inventer un identifiant
        // technique, mais on lui laisse la main s'il doit coller à l'hôte.
        $cle = ShopMetric::cleanKey((string) $request->request->get('key', '') ?: $libelle);

        if ($libelle === '' || $cle === '') {
            $this->addFlash('error', 'admin.targets.errorMetricEmpty');

            return $this->redirectToRoute('admin_targets');
        }

        $this->hr->saveMetric(new ShopMetric(
            $cle,
            $libelle,
            mb_substr(trim((string) $request->request->get('unit', '')), 0, 24),
            $request->request->getBoolean('lowerIsBetter'),
            max(0, (int) $request->request->get('sortOrder', 0)),
        ));

        $this->store->audit($admin->id, $admin->role->value, 'SHOP_METRIC_SAVED', null, null, ['metricKey' => $cle]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_targets');
    }

    #[Route('/indicateur/{key}/supprimer', name: 'admin_targets_metric_delete', methods: ['POST'])]
    public function deleteMetric(string $key): Response
    {
        $admin = $this->currentUser->requireAdmin();

        $this->hr->deleteMetric($key);

        $this->store->audit($admin->id, $admin->role->value, 'SHOP_METRIC_DELETED', null, null, ['metricKey' => $key]);
        $this->addFlash('success', 'common.saved');

        return $this->redirectToRoute('admin_targets');
    }

    // ── Lecture des paramètres d'écran ──────────────────────────────────────

    private function moisDemande(Request $request): TargetMonth
    {
        $brut = trim((string) ($request->request->get('mois') ?? $request->query->get('mois', '')));

        if (preg_match('/^(\d{4})-(\d{2})$/', $brut, $m) === 1) {
            $mois = TargetMonth::of((int) $m[1], (int) $m[2]);

            if ($mois !== null) {
                return $mois;
            }
        }

        // Le mois COURANT par défaut, lu sur la date métier : c'est celui
        // qu'on vient régler neuf fois sur dix.
        return TargetMonth::fromDate($this->inventory->today())
            ?? TargetMonth::of(TargetMonth::FIRST_YEAR, 1)
            ?? throw new \LogicException('TARGET_MONTH_UNAVAILABLE');
    }

    private function boutiqueDemandee(Request $request): string
    {
        $brut = trim((string) ($request->request->get('boutique') ?? $request->query->get('boutique', '')));

        if ($brut !== '' && in_array($brut, $this->boutiques(), true)) {
            return $brut;
        }

        return $this->boutiques()[0] ?? ($this->ranking->currentShopId() ?? 'boutique');
    }

    /**
     * Les boutiques connues — celles que porte l'équipe.
     *
     * Rien en dur : c'est la même liste que l'écran Équipe propose en
     * autocomplétion, et elle se remplit à mesure qu'on affecte des personnes.
     * Une boutique sans personne affectée n'existe pour ainsi dire pas — et
     * lui poser des objectifs n'apprendrait rien à personne.
     *
     * @return list<string>
     */
    private function boutiques(): array
    {
        /*
          Les boutiques ENREGISTRÉES d'abord.

          Cette liste se déduisait des noms tapés sur les fiches consultants :
          un texte libre, donc « Rynek », « rynek » et « Wrocław Rynek »
          cohabitaient et faisaient trois boutiques dans le sélecteur. Depuis
          qu'Admin ▸ Boutiques existe, c'est lui qui fait foi.
        */
        $vues = [];

        foreach ($this->shops->all() as $boutique) {
            $vues[$boutique->name] = true;
        }

        /*
          Puis les noms déjà EMPLOYÉS, même s'ils ne correspondent à aucune
          fiche.

          Un objectif posé l'an dernier sur « Merisù Centrum » doit rester
          atteignable : le retirer du sélecteur ne l'aurait pas effacé, il
          l'aurait rendu invisible — des chiffres en base que plus aucun écran
          ne sait afficher.
        */
        foreach ($this->consultants->consultants() as $consultant) {
            foreach ($consultant->shops as $boutique) {
                $vues[$boutique] = true;
            }
        }

        $noms = array_keys(array_filter($vues, static fn (bool $v, string $nom): bool => trim($nom) !== '', \ARRAY_FILTER_USE_BOTH));
        sort($noms, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $noms;
    }
}
