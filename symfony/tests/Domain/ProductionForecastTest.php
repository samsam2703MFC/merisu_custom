<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\ForecastDay;
use Merisu\Inventory\Domain\ProductionForecast;
use Merisu\Inventory\Domain\TemperatureBand;
use Merisu\Inventory\Domain\WeatherKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionForecastTest extends TestCase
{
    /**
     * Six semaines de ventes, du 2026-07-16 au 2026-08-26.
     *
     * Chaque jour de semaine a sa propre valeur, constante d'une semaine à
     * l'autre : la moyenne d'un jour vaut donc exactement cette valeur, et un
     * écart dans le résultat ne peut venir que du calcul qu'on teste.
     *
     * @return array<string, array<string, float>>
     */
    private static function ventes(): array
    {
        $parJour = [
            'MON' => 100.0, 'TUE' => 120.0, 'WED' => 120.0, 'THU' => 140.0,
            'FRI' => 180.0, 'SAT' => 240.0, 'SUN' => 260.0,
        ];

        $ventes = [];
        $jour = new \DateTimeImmutable('2026-07-16');

        for ($i = 0; $i < 42; $i++) {
            $date = $jour->format('Y-m-d');
            $total = $parJour[strtoupper($jour->format('D'))];

            // Trois tailles, part stable : 50 / 30 / 20.
            $ventes[$date] = [
                'REG' => $total * 0.5,
                'GRA' => $total * 0.3,
                'EXT' => $total * 0.2,
            ];

            $jour = $jour->modify('+1 day');
        }

        return $ventes;
    }

    private static function temps(string $date, float $tempMax, WeatherKind $ciel = WeatherKind::Sunny): ForecastDay
    {
        $t = strtotime($date);

        return ForecastDay::of(
            $date,
            DayOfWeek::from(strtoupper(gmdate('D', (int) $t))),
            $ciel,
            $tempMax - 9,
            $tempMax,
            0,
        );
    }

    /**
     * La base est celle du JOUR DE SEMAINE, pas une moyenne générale.
     *
     * Un samedi vend deux fois et demie ce que vend un lundi : une moyenne
     * tous jours confondus aurait fait produire trop le lundi et pas assez le
     * samedi, chaque semaine, indéfiniment.
     */
    #[DataProvider('joursEtBases')]
    public function testLaBaseSuitLeJourDeSemaine(string $date, float $base): void
    {
        $plan = ProductionForecast::build(self::ventes(), [], [], [], [$date], 0.0);

        self::assertSame($base, $plan->days[0]->base);
        self::assertSame(6, $plan->days[0]->observedDays);
        self::assertTrue($plan->days[0]->isSolid());
    }

    /** @return iterable<string, array{string, float}> */
    public static function joursEtBases(): iterable
    {
        yield 'lundi' => ['2026-09-07', 100.0];
        yield 'jeudi' => ['2026-09-10', 140.0];
        yield 'samedi' => ['2026-09-12', 240.0];
        yield 'dimanche' => ['2026-09-13', 260.0];
    }

    public function testLesTroisFacteursSeMultiplient(): void
    {
        $plan = ProductionForecast::build(
            self::ventes(),
            ['2026-09-10' => self::temps('2026-09-10', 28.0)],
            [TemperatureBand::Warm->value => 3.0],
            [],
            ['2026-09-10'],
            8.0,
        );

        $jour = $plan->days[0];

        self::assertSame(140.0, $jour->base);
        self::assertSame(3.0, $jour->weatherPercent);
        self::assertSame(8.0, $jour->targetPercent);
        // 140 × 1,03 × 1,08 = 155,736 → 156, arrondi à l'entier SUPÉRIEUR :
        // produire 155 laisserait un client repartir sans rien.
        self::assertSame(156, $jour->pieces);
    }

    /**
     * Sans météo pour ce jour, le facteur est ABSENT — il ne vaut pas zéro par
     * défaut sans le dire. L'écran doit pouvoir signaler que la prévision
     * manque plutôt que de laisser croire à un temps neutre.
     */
    public function testUneJourneeSansMeteoNAucuneCorrection(): void
    {
        $plan = ProductionForecast::build(self::ventes(), [], [5.0], [], ['2026-09-10'], 10.0);

        $jour = $plan->days[0];

        self::assertNull($jour->weatherPercent);
        self::assertFalse($jour->hasWeather());
        self::assertNull($jour->band);
        // 140 × 1,10 = 154 — l'objectif s'applique quand même.
        self::assertSame(154, $jour->pieces);
    }

    /**
     * La tranche de température l'emporte sur le ciel.
     *
     * Cumuler les deux compterait deux fois la même journée chaude et
     * ensoleillée, et l'écart mesuré porte déjà l'un et l'autre.
     */
    public function testLaTemperatureLEmporteSurLeCiel(): void
    {
        $plan = ProductionForecast::build(
            self::ventes(),
            ['2026-09-10' => self::temps('2026-09-10', 28.0, WeatherKind::Sunny)],
            [TemperatureBand::Warm->value => 3.0],
            [WeatherKind::Sunny->value => 25.0],
            ['2026-09-10'],
            0.0,
        );

        self::assertSame(3.0, $plan->days[0]->weatherPercent);
    }

    /** Faute de tranche réglée, le ciel prend le relais plutôt que rien. */
    public function testLeCielSertDeReplyQuandLaTrancheNEstPasReglee(): void
    {
        $plan = ProductionForecast::build(
            self::ventes(),
            ['2026-09-10' => self::temps('2026-09-10', 28.0, WeatherKind::Rain)],
            [],
            [WeatherKind::Rain->value => -6.0],
            ['2026-09-10'],
            0.0,
        );

        self::assertSame(-6.0, $plan->days[0]->weatherPercent);
        // 140 × 0,94 = 131,6 → 132
        self::assertSame(132, $plan->days[0]->pieces);
    }

    /**
     * Une base tirée de deux samedis est un souvenir, pas une moyenne. Elle est
     * rendue quand même — il faut bien produire — mais signalée.
     */
    public function testUneBaseTropMinceEstSignalee(): void
    {
        $ventes = [
            '2026-08-15' => ['REG' => 200.0],
            '2026-08-22' => ['REG' => 300.0],
        ];

        $plan = ProductionForecast::build($ventes, [], [], [], ['2026-09-12'], 0.0);

        $jour = $plan->days[0];

        self::assertSame(2, $jour->observedDays);
        self::assertFalse($jour->isSolid());
        self::assertSame(250.0, $jour->base);
        self::assertSame(250, $jour->pieces);
    }

    public function testSansAucuneVenteLePlanEstNulEtNExplosePas(): void
    {
        $plan = ProductionForecast::build([], [], [], [], ['2026-09-10'], 12.0);

        self::assertSame(0.0, $plan->days[0]->base);
        self::assertSame(0, $plan->days[0]->observedDays);
        self::assertSame(0, $plan->days[0]->pieces);
        self::assertSame(0, $plan->totalPieces);
        self::assertSame([], $plan->mix);
    }

    /** Le partage entre tailles vient des ventes, il ne se devine pas. */
    public function testLaRepartitionSuitLePartageObserve(): void
    {
        $plan = ProductionForecast::build(self::ventes(), [], [], [], ['2026-09-10'], 0.0);

        $parts = $plan->piecesByProduct(140);

        self::assertSame(70, $parts['REG']);
        self::assertSame(42, $parts['GRA']);
        self::assertSame(28, $parts['EXT']);
        self::assertSame(140, array_sum($parts));
    }

    public function testLesMatieresSuiventLesCompositions(): void
    {
        $plan = ProductionForecast::build(self::ventes(), [], [], [], ['2026-09-10'], 0.0);

        $matieres = $plan->materials(
            140,
            ['p-reg' => ['creme' => 100.0, 'savoiardi' => 2.0], 'p-gra' => ['creme' => 120.0, 'savoiardi' => 3.0], 'p-ext' => ['creme' => 140.0, 'savoiardi' => 3.5]],
            ['REG' => 'p-reg', 'GRA' => 'p-gra', 'EXT' => 'p-ext'],
        );

        // 70×100 + 42×120 + 28×140 = 7000 + 5040 + 3920 = 15 960 g
        self::assertSame(15960.0, $matieres['creme']);
        // 70×2 + 42×3 + 28×3,5 = 140 + 126 + 98 = 364
        self::assertSame(364.0, $matieres['savoiardi']);
    }

    /**
     * Un produit vendu SANS composition ne se voit prêter la recette de
     * personne : ses matières manqueraient à la commande, mais lui prêter
     * celle du voisin la gonflerait sans que rien ne le dise. L'écran le
     * nomme, c'est tout ce qu'on peut faire honnêtement.
     */
    public function testUnProduitSansCompositionEstNommeEtNonDevine(): void
    {
        $plan = ProductionForecast::build(self::ventes(), [], [], [], ['2026-09-10'], 0.0, [
            'EXT' => 'Tiramisu Extra',
        ]);

        $recettes = ['p-reg' => ['creme' => 100.0]];
        $refs = ['REG' => 'p-reg', 'GRA' => 'p-gra', 'EXT' => 'p-ext'];

        self::assertSame(7000.0, $plan->materials(140, $recettes, $refs)['creme']);
        self::assertSame(['GRA', 'Tiramisu Extra'], $plan->productsWithoutRecipe($recettes, $refs));
    }

    public function testLaFormuleSeRelitSansLEcran(): void
    {
        $plan = ProductionForecast::build(
            self::ventes(),
            ['2026-09-10' => self::temps('2026-09-10', 28.0)],
            [TemperatureBand::Warm->value => 3.0],
            [],
            ['2026-09-10'],
            8.0,
        );

        self::assertSame('140,0 · météo +3,0 % · objectif +8,0 %', $plan->days[0]->formula());
    }

    /** Un facteur nul n'encombre pas la formule. */
    public function testLaFormuleTaitCeQuiNeChangeRien(): void
    {
        $plan = ProductionForecast::build(self::ventes(), [], [], [], ['2026-09-10'], 0.0);

        self::assertSame('140,0', $plan->days[0]->formula());
    }
}
