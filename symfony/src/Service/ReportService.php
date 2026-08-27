<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Adapter\RecipeServiceInterface;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\Delta;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\NetVariance;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\Production;
use Merisu\Inventory\Store\Store;

/**
 * Rapports d'administration (§6).
 *
 * Principe directeur : aucun résultat n'est présenté comme certain tant que les
 * données sont incomplètes. Chaque rapport porte un drapeau `partial` et le
 * détail de ce qui manque.
 */
final class ReportService
{
    public function __construct(
        private readonly Store $store,
        private readonly RecipeServiceInterface $recipes,
    ) {
    }

    /**
     * §5.1 sur une période — écart net par produit et par jour.
     *
     * @return array{
     *     from: string, to: string, rows: list<array{date: string, product: Product, net: mixed}>,
     *     totals: array<string, array{total: float, skipped: int}>,
     *     missing: list<array{date: string, productId: string, missing: string}>, partial: bool
     * }
     */
    public function dailyNet(string $from, string $to, ?string $workstationId = null): array
    {
        $products = $this->store->products();
        $counts = $this->store->counts(array_filter([
            'from' => $from,
            'to' => $to,
            'workstationId' => $workstationId,
        ]));

        // Indexation en amont : évite de reparcourir tous les comptages par jour.
        $index = [];
        foreach ($counts as $count) {
            $index[$count->date][$count->productId][$count->moment->value] = $count;
        }

        $rows = [];
        $totals = [];
        $missing = [];

        foreach (BusinessDate::range($from, $to) as $date) {
            foreach ($products as $product) {
                $dayCounts = $index[$date][$product->id] ?? null;
                if ($dayCounts === null) {
                    continue; // journée sans activité : on ne l'invente pas
                }

                $opening = $dayCounts[CountMoment::Open0800->value] ?? null;
                $closing = $dayCounts[CountMoment::Close2200->value] ?? null;

                $net = NetVariance::compute(
                    $date,
                    $product->id,
                    $workstationId ?? ($opening?->workstationId ?? $closing?->workstationId ?? ''),
                    $opening?->qty,
                    $closing?->qty,
                    ($opening?->producedQty ?? 0) + ($closing?->producedQty ?? 0),
                );

                $rows[] = ['date' => $date, 'product' => $product, 'net' => $net];
                $totals[$product->id] ??= ['total' => 0.0, 'skipped' => 0];

                if ($net->netConsumption === null) {
                    ++$totals[$product->id]['skipped'];
                    $missing[] = [
                        'date' => $date,
                        'productId' => $product->id,
                        'missing' => $opening === null && $closing === null ? 'BOTH' : ($opening === null ? 'OPEN' : 'CLOSE'),
                    ];
                } else {
                    $totals[$product->id]['total'] += $net->netConsumption;
                }
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => $totals,
            'missing' => $missing,
            'partial' => $missing !== [],
        ];
    }

    /**
     * §6 — Delta technique.
     *
     * Pièces produites retenues : les plans FIGÉS de la période, c'est-à-dire ce
     * qui a effectivement été demandé à la production.
     *
     * @return array{
     *     from: string, to: string, deltas: list<mixed>, summary: array{materials: int, outOfTolerance: int, partial: bool},
     *     tolerance: float, producedByProduct: array<string,float>, productsWithoutRecipe: list<string>,
     *     materialsWithoutRealData: list<string>, partial: bool
     * }
     */
    public function delta(string $from, string $to, string|array|null $workstationId = null): array
    {
        $settings = $this->store->settings();
        $plans = $this->store->plansBetween($from, $to, $workstationId);

        $producedByProduct = [];
        foreach ($plans as $line) {
            $producedByProduct[$line->productId] = ($producedByProduct[$line->productId] ?? 0) + $line->qtyToProduce;
        }

        $recipes = $this->recipes->recipes(array_keys($producedByProduct));
        $theoretical = Delta::theoreticalConsumption($producedByProduct, $recipes);

        $realByMaterial = [];
        foreach ($this->store->materialMovements($from, $to, $workstationId) as $movement) {
            $realByMaterial[$movement->materialId] = ($realByMaterial[$movement->materialId] ?? 0) + $movement->realQty;
        }

        $deltas = Delta::materialDeltas($theoretical['byMaterial'], $realByMaterial, $settings->deltaTolerance);

        $withoutReal = array_values(array_map(
            static fn ($d): string => $d->materialId,
            array_filter($deltas, static fn ($d): bool => $d->partial),
        ));

        return [
            'from' => $from,
            'to' => $to,
            'deltas' => $deltas,
            'summary' => Delta::summarize($deltas),
            'tolerance' => $settings->deltaTolerance,
            'producedByProduct' => $producedByProduct,
            'productsWithoutRecipe' => $theoretical['productsWithoutRecipe'],
            'materialsWithoutRealData' => $withoutReal,
            'partial' => $plans === [] || $theoretical['productsWithoutRecipe'] !== [] || $withoutReal !== [] || $deltas === [],
        ];
    }

    /**
     * Vue matricielle jour × produit (§6) : seuil, ouverture, clôture, écart net.
     *
     * @return array{
     *     from: string, to: string, days: list<array{date: string, dayOfWeek: mixed}>,
     *     products: list<Product>, cells: array<string, array<string, array<string, float|null>>>
     * }
     */
    public function matrix(string $from, string $to, string|array|null $workstationId = null): array
    {
        $products = $this->store->products(activeOnly: true);
        $parMatrix = $this->store->parMatrix();
        $counts = $this->store->counts(array_filter([
            'from' => $from,
            'to' => $to,
            'workstationId' => $workstationId,
        ]));

        /*
          Les comptages sont SOMMÉS, pas écrasés.

          Cette table indexait par date × produit × moment, une seule valeur
          par case. Sur un poste unique c'était juste. Dès qu'une boutique en
          compte deux — un comptoir et un labo —, la seconde saisie effaçait la
          première : le stock d'un poste s'affichait comme celui de la
          boutique, et rien ne signalait la moitié manquante.

          `null` reste distinct de zéro : une case sans comptage ne doit pas se
          lire « il n'y avait rien », sans quoi un poste non saisi ressemble à
          un stock épuisé.
        */
        $index = [];
        foreach ($counts as $count) {
            $case = &$index[$count->date][$count->productId][$count->moment->value];
            $case = ($case ?? 0.0) + $count->qty;
            unset($case);
        }

        $days = [];
        foreach (BusinessDate::range($from, $to) as $date) {
            $days[] = ['date' => $date, 'dayOfWeek' => BusinessDate::dayOfWeek($date)];
        }

        $cells = [];
        foreach ($products as $product) {
            foreach ($days as $day) {
                $dayCounts = $index[$day['date']][$product->id] ?? [];
                $opening = $dayCounts[CountMoment::Open0800->value] ?? null;
                $closing = $dayCounts[CountMoment::Close2200->value] ?? null;

                $cells[$product->id][$day['date']] = [
                    'required' => self::requiredForScope(
                        $parMatrix,
                        $product->id,
                        $day['dayOfWeek'],
                        $workstationId,
                    ),
                    'opening' => $opening,
                    'closing' => $closing,
                    'net' => $opening === null || $closing === null
                        ? null
                        : round($opening - $closing, 9),
                ];
            }
        }

        return ['from' => $from, 'to' => $to, 'days' => $days, 'products' => $products, 'cells' => $cells];
    }

    /**
     * Le seuil attendu sur le PÉRIMÈTRE, et non sur un poste.
     *
     * Un seuil se définit par poste. Quand le rapport en couvre plusieurs — le
     * cas dès qu'une boutique a un comptoir et un labo —, « ce qu'il faut
     * avoir » est la SOMME de ce qu'il faut à chacun : c'est la même maille
     * que les comptages, qu'on vient d'additionner. Comparer une somme de
     * stocks au seuil d'un seul poste aurait annoncé un surstock permanent.
     *
     * @param string|list<string>|null $workstationId
     */
    private static function requiredForScope(
        array $parMatrix,
        string $productId,
        DayOfWeek $dayOfWeek,
        string|array|null $workstationId,
    ): ?float {
        if (!\is_array($workstationId)) {
            return Production::resolveRequiredPieces($parMatrix, $productId, $dayOfWeek, $workstationId);
        }

        $total = null;

        foreach ($workstationId as $poste) {
            $seuil = Production::resolveRequiredPieces($parMatrix, $productId, $dayOfWeek, $poste);

            // `null` n'est pas zéro : un poste sans seuil n'en impose aucun, et
            // le compter pour 0 aurait dilué le seuil réel des autres.
            if ($seuil !== null) {
                $total = ($total ?? 0.0) + $seuil;
            }
        }

        return $total;
    }

    /** Export CSV du delta technique (§6 — export CSV/Excel). */
    public function deltaCsv(string $from, string $to, Locale $locale, ?string $workstationId = null): string
    {
        $report = $this->delta($from, $to, $workstationId);
        $default = $this->store->settings()->defaultLocale;

        $rows = [[
            'material_id', 'material_name', 'unit', 'theoretical_qty',
            'real_qty', 'delta', 'delta_pct', 'out_of_tolerance', 'partial',
        ]];

        foreach ($report['deltas'] as $delta) {
            $material = $this->recipes->material($delta->materialId);
            $rows[] = [
                $delta->materialId,
                $material?->label($locale, $default) ?? $delta->materialId,
                $material?->unit ?? '',
                (string) $delta->theoreticalQty,
                $delta->realQty === null ? '' : (string) $delta->realQty,
                $delta->delta === null ? '' : (string) $delta->delta,
                $delta->deltaPct === null ? '' : number_format($delta->deltaPct * 100, 2, '.', ''),
                $delta->outOfTolerance ? '1' : '0',
                $delta->partial ? '1' : '0',
            ];
        }

        return self::toCsv($rows);
    }

    /** Export CSV de la vue matricielle. */
    public function matrixCsv(string $from, string $to, Locale $locale, ?string $workstationId = null): string
    {
        $report = $this->matrix($from, $to, $workstationId);
        $default = $this->store->settings()->defaultLocale;

        $rows = [[
            'date', 'day_of_week', 'product_code', 'product_name', 'unit',
            'required_pieces', 'opening_stock', 'closing_stock', 'net_consumption',
        ]];

        foreach ($report['days'] as $day) {
            foreach ($report['products'] as $product) {
                $cell = $report['cells'][$product->id][$day['date']] ?? null;
                if ($cell === null) {
                    continue;
                }
                $rows[] = [
                    $day['date'],
                    $day['dayOfWeek']->value,
                    $product->code,
                    $product->label($locale, $default),
                    $product->unit,
                    $cell['required'] === null ? '' : (string) $cell['required'],
                    $cell['opening'] === null ? '' : (string) $cell['opening'],
                    $cell['closing'] === null ? '' : (string) $cell['closing'],
                    $cell['net'] === null ? '' : (string) $cell['net'],
                ];
            }
        }

        return self::toCsv($rows);
    }

    /**
     * Sérialisation CSV avec échappement RFC 4180.
     *
     * Le BOM UTF-8 est ajouté par le contrôleur au moment du téléchargement :
     * sans lui, Excel affiche mal les accents des libellés FR/PL/IT/ES.
     *
     * @param list<list<string>> $rows
     */
    public static function toCsv(array $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $cells = array_map(static function (string $cell): string {
                return preg_match('/[",\n;]/', $cell) === 1
                    ? '"' . str_replace('"', '""', $cell) . '"'
                    : $cell;
            }, $row);

            $lines[] = implode(',', $cells);
        }

        return implode("\r\n", $lines);
    }
}
