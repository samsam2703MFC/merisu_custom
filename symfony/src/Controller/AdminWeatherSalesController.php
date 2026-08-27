<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\TemperatureBand;
use Merisu\Inventory\Domain\WeatherKind;
use Merisu\Inventory\Domain\WeatherSalesAnalysis;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Service\ReportScope;
use Merisu\Inventory\Store\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin ▸ Ventes & météo — ce que le temps fait aux ventes.
 *
 * L'écran qui manquait entre deux écrans qui existaient : Météo sait quel
 * temps il a fait, Ventes sait ce qui s'est vendu, et personne ne les croisait.
 *
 * ── Trois lectures, parce qu'une seule tromperait
 *
 * La TEMPÉRATURE, le CIEL et le JOUR DE SEMAINE sont montrés côte à côte, et
 * c'est délibéré. Pris seul, un tableau par température laisse croire que la
 * chaleur explique tout — alors que les jours chauds tombent en août, et qu'en
 * août il y a des touristes. Le tableau des jours de semaine, juste à côté,
 * montre un écart du même ordre entre un mardi et un samedi : de quoi se
 * rappeler qu'aucune de ces colonnes ne mesure une cause.
 *
 * C'est une aide au réglage, pas une démonstration. L'écran le dit en toutes
 * lettres, parce qu'un tableau de pourcentages ne le dit pas de lui-même.
 *
 * ── Le report est un GESTE, pas une conséquence
 *
 * Les écarts calculés ne descendent PAS d'eux-mêmes dans les seuils. Un bouton
 * les y porte, et seulement ceux qui reposent sur assez de journées. Reporter
 * en silence à chaque affichage aurait fait bouger le plan de production au
 * gré des relevés, sans que personne ne décide rien.
 */
#[Route('/admin/ventes-meteo')]
final class AdminWeatherSalesController extends AbstractController
{
    public function __construct(
        private readonly Store $store,
        private readonly ReportScope $scope,
        private readonly CurrentUser $currentUser,
    ) {
    }

    #[Route('', name: 'admin_weather_sales', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->currentUser->requireAdmin();

        [$depuis, $jusqu] = $this->range($request);
        $boutique = $this->scope->salesFilter($request);
        $choisie = $this->scope->selected($request);

        $ventesParJour = $this->dailyTotals($depuis, $jusqu, $boutique);
        $journal = $this->store->weatherJournal($depuis, $jusqu);

        // ── Les trois regroupements ────────────────────────────────────────
        $parTemperature = [];
        $parCiel = [];
        $parJour = [];

        foreach ($journal as $date => $jour) {
            // La température MAXIMALE, et non la moyenne : c'est celle de
            // l'après-midi, quand la boutique vend. Une nuit à 4 °C ne dit
            // rien de la file à seize heures.
            $tranche = TemperatureBand::of($jour->tempMax);

            if ($tranche !== null) {
                $parTemperature[$date] = $tranche->value;
            }

            $parCiel[$date] = $jour->kind->value;
            $parJour[$date] = $jour->dayOfWeek->value;
        }

        $temperature = WeatherSalesAnalysis::build($ventesParJour, $parTemperature, array_map(
            static fn (TemperatureBand $b): string => $b->value,
            TemperatureBand::all(),
        ));

        $ciel = WeatherSalesAnalysis::build($ventesParJour, $parCiel, array_map(
            static fn (WeatherKind $k): string => $k->value,
            WeatherKind::all(),
        ));

        $semaine = WeatherSalesAnalysis::build($ventesParJour, $parJour, array_map(
            static fn (DayOfWeek $j): string => $j->value,
            DayOfWeek::all(),
        ));

        return $this->render('admin/weather_sales.html.twig', [
            'from' => $depuis,
            'to' => $jusqu,
            'scopeShops' => $this->scope->shops(),
            'scopeSelected' => $this->scope->selected($request),
            'temperature' => $temperature,
            'sky' => $ciel,
            'weekday' => $semaine,
            'bands' => TemperatureBand::all(),
            'kinds' => WeatherKind::all(),
            'days' => DayOfWeek::all(),
            'minDays' => WeatherSalesAnalysis::MIN_DAYS,
            // Ce qui est POSÉ aujourd'hui dans les seuils, en regard de ce qui
            // est calculé : sans cette colonne, on reporterait sans savoir ce
            // qu'on écrase.
            'currentBands' => $this->store->temperatureRatios(),
            'currentKinds' => $this->store->weatherRatios(),
            'salesRange' => $this->store->salesRange(),
            'journalRange' => $this->store->weatherJournalRange(),
        ]);
    }

    /**
     * Reporte les écarts calculés dans les seuils.
     *
     * Seuls ceux qui reposent sur assez de journées : les autres ne sont même
     * pas proposés à l'écran, et les recalculer ici les ferait rentrer par la
     * fenêtre.
     *
     * L'intervalle et la boutique sont RELUS depuis le formulaire, et l'analyse
     * refaite : reporter des chiffres transmis par le navigateur reviendrait à
     * écrire dans les seuils ce que le formulaire dit, et non ce que les ventes
     * disent.
     */
    #[Route('/reporter', name: 'admin_weather_sales_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        $admin = $this->currentUser->requireAdmin();

        [$depuis, $jusqu] = $this->range($request);
        $boutique = $this->scope->salesFilter($request);
        $quoi = (string) $request->request->get('scope', '');

        $ventesParJour = $this->dailyTotals($depuis, $jusqu, $boutique);
        $journal = $this->store->weatherJournal($depuis, $jusqu);

        $pose = 0;

        if ($quoi === 'temperature' || $quoi === 'tout') {
            $cles = [];
            foreach ($journal as $date => $jour) {
                $tranche = TemperatureBand::of($jour->tempMax);
                if ($tranche !== null) {
                    $cles[$date] = $tranche->value;
                }
            }

            $analyse = WeatherSalesAnalysis::build($ventesParJour, $cles, array_map(
                static fn (TemperatureBand $b): string => $b->value,
                TemperatureBand::all(),
            ));

            foreach ($analyse->reliableDeviations() as $cle => $ecart) {
                $this->store->saveTemperatureRatio(TemperatureBand::fromLoose($cle), $ecart);
                ++$pose;
            }
        }

        if ($quoi === 'sky' || $quoi === 'tout') {
            $cles = [];
            foreach ($journal as $date => $jour) {
                $cles[$date] = $jour->kind->value;
            }

            $analyse = WeatherSalesAnalysis::build($ventesParJour, $cles, array_map(
                static fn (WeatherKind $k): string => $k->value,
                WeatherKind::all(),
            ));

            foreach ($analyse->reliableDeviations() as $cle => $ecart) {
                $this->store->saveWeatherRatio(WeatherKind::fromLoose($cle), $ecart);
                ++$pose;
            }
        }

        $this->store->audit($admin->id, $admin->role->value, 'WEATHER_RATIOS_APPLIED', null, null, [
            'scope' => $quoi,
            'from' => $depuis,
            'to' => $jusqu,
            // Le PÉRIMÈTRE réellement lu, pas le seul choix : c'est lui qui
            // explique les taux qu'on vient d'écrire.
            'shops' => $boutique,
            'applied' => $pose,
        ]);

        $this->addFlash(
            $pose > 0 ? 'success' : 'error',
            $pose > 0 ? 'admin.weatherSales.applied' : 'admin.weatherSales.nothingToApply',
        );

        return $this->redirectToRoute('admin_weather_sales', [
            'depuis' => $depuis,
            'jusqu' => $jusqu,
            'boutique' => $choisie?->code ?? '',
        ]);
    }

    /**
     * Les ventes RAMENÉES À LA JOURNÉE.
     *
     * Le journal météo porte une ligne par jour ; les ventes en portent une par
     * produit et par jour. Les additionner ici plutôt que dans l'analyse laisse
     * celle-ci ignorer ce qu'est un produit — elle ne fait que des moyennes.
     *
     * @return array<string, array{units: float, revenue: float}>
     */
    /** @param list<string>|null $shopCode */
    private function dailyTotals(string $from, string $to, ?array $shopCode): array
    {
        $parJour = [];

        foreach ($this->store->sales($from, $to, $shopCode) as $vente) {
            $parJour[$vente->date]['units'] = ($parJour[$vente->date]['units'] ?? 0.0) + $vente->quantity;
            $parJour[$vente->date]['revenue'] = ($parJour[$vente->date]['revenue'] ?? 0.0) + $vente->revenue;
        }

        return $parJour;
    }

    /**
     * L'intervalle demandé, ou TOUT ce qui est relevé.
     *
     * Par défaut l'historique entier, et non le mois courant : l'écart d'une
     * tranche de température ne se voit pas sur trente jours, où deux ou trois
     * tranches seulement sont représentées.
     *
     * @return array{string, string}
     */
    private function range(Request $request): array
    {
        $releve = $this->store->salesRange();

        $defautDepuis = $releve['from'] ?? gmdate('Y-m-d', strtotime('-1 year'));
        $defautJusqu = $releve['to'] ?? gmdate('Y-m-d');

        $depuis = self::date($request->query->get('depuis') ?? $request->request->get('depuis'), $defautDepuis);
        $jusqu = self::date($request->query->get('jusqu') ?? $request->request->get('jusqu'), $defautJusqu);

        // Un intervalle à l'envers ne rend rien et n'explique rien : on le
        // remet à l'endroit plutôt que d'afficher un tableau vide.
        return $depuis <= $jusqu ? [$depuis, $jusqu] : [$jusqu, $depuis];
    }

    /** Une date AAAA-MM-JJ, ou celle de repli. */
    private static function date(mixed $value, string $fallback): string
    {
        $texte = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $texte) === 1 ? $texte : $fallback;
    }
}
