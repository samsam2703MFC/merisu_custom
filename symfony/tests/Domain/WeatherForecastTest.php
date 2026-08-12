<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\ForecastDay;
use Merisu\Inventory\Domain\WeatherCode;
use Merisu\Inventory\Domain\WeatherForecast;
use Merisu\Inventory\Domain\WeatherKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeatherForecastTest extends TestCase
{
    /** Minuit UTC, le lundi 3 août 2026. */
    private const LUNDI = 1785715200;

    private const JOUR = 86400;

    /**
     * Une journée telle qu'OpenWeatherMap la rend.
     *
     * @return array<string, mixed>
     */
    private static function ligne(int $dt, int $code, float $min = 12.0, float $max = 24.0, float $pop = 0.2): array
    {
        return [
            'dt' => $dt,
            'temp' => ['min' => $min, 'max' => $max, 'day' => 20.0],
            'weather' => [['id' => $code, 'main' => 'Test', 'description' => 'test', 'icon' => '01d']],
            'pop' => $pop,
            'summary' => 'Journée d\'essai',
        ];
    }

    // ── Le code météo ───────────────────────────────────────────────────────

    /**
     * Les frontières de chaque groupe, pas leur milieu : c'est là que se
     * jouent les erreurs de report.
     */
    #[DataProvider('codes')]
    public function testChaqueGroupeTombeDansLeBonTemps(int $code, ?WeatherKind $attendu): void
    {
        self::assertSame($attendu, WeatherCode::toKind($code));
    }

    /** @return iterable<string, array{int, WeatherKind|null}> */
    public static function codes(): iterable
    {
        yield 'orage' => [200, WeatherKind::Rain];
        yield 'orage, fin du groupe' => [232, WeatherKind::Rain];
        yield 'bruine' => [300, WeatherKind::Rain];
        yield 'pluie' => [500, WeatherKind::Rain];
        yield 'pluie verglaçante' => [511, WeatherKind::Rain];
        yield 'averse' => [531, WeatherKind::Rain];
        yield 'neige, début du groupe' => [600, WeatherKind::Snow];
        yield 'neige et pluie mêlées' => [616, WeatherKind::Snow];
        yield 'neige, fin du groupe' => [622, WeatherKind::Snow];
        yield 'brume' => [701, WeatherKind::Cloudy];
        yield 'brouillard' => [741, WeatherKind::Cloudy];
        yield 'tornade' => [781, WeatherKind::Cloudy];
        yield 'ciel clair' => [800, WeatherKind::Sunny];
        yield 'quelques nuages' => [801, WeatherKind::Sunny];
        yield 'nuages épars' => [802, WeatherKind::Cloudy];
        yield 'ciel couvert' => [804, WeatherKind::Cloudy];
        yield 'code inventé' => [42, null];
        yield 'zéro' => [0, null];
        yield 'au-delà du dernier groupe' => [900, null];
    }

    /**
     * Deux conditions le même jour : c'est la plus marquante qui décide.
     *
     * Prendre la première venue aurait pu annoncer du soleil un jour de grêle.
     */
    public function testLaConditionLaPlusMarquanteEmporte(): void
    {
        self::assertSame(
            WeatherKind::Rain,
            WeatherCode::dominant([['id' => 800], ['id' => 500]]),
        );

        self::assertSame(
            WeatherKind::Snow,
            WeatherCode::dominant([['id' => 500], ['id' => 601], ['id' => 802]]),
        );
    }

    public function testUneListeIllisibleNeRendAucunTemps(): void
    {
        self::assertNull(WeatherCode::dominant(null));
        self::assertNull(WeatherCode::dominant([]));
        self::assertNull(WeatherCode::dominant('pluie'));
        self::assertNull(WeatherCode::dominant([['main' => 'Rain']]));
        self::assertNull(WeatherCode::dominant([['id' => 42]]));
    }

    // ── Une journée ─────────────────────────────────────────────────────────

    public function testUneJourneeSeLitEntierement(): void
    {
        $jour = ForecastDay::fromHost(self::ligne(self::LUNDI, 500, 11.0, 19.0, 0.75), 0);

        self::assertNotNull($jour);
        self::assertSame('2026-08-03', $jour->date);
        self::assertSame(DayOfWeek::Mon, $jour->dayOfWeek);
        self::assertSame(WeatherKind::Rain, $jour->kind);
        self::assertSame(11.0, $jour->tempMin);
        self::assertSame(19.0, $jour->tempMax);
        self::assertSame(75, $jour->rainChance);
        self::assertSame("Journée d'essai", $jour->summary);
    }

    /**
     * LE point où tout se joue.
     *
     * À Varsovie en été, la journée du 3 août commence à 22 h UTC le 2. Sans
     * le décalage, toute la semaine glisse d'un jour et l'on produit le lundi
     * ce qu'il fallait le mardi.
     */
    public function testLeDecalageHoraireDecideDuJour(): void
    {
        // 22 h UTC le dimanche 2 août : minuit du lundi à Varsovie (UTC+2).
        $veille = self::LUNDI - 2 * 3600;

        $sansDecalage = ForecastDay::fromHost(self::ligne($veille, 800), 0);
        $avecDecalage = ForecastDay::fromHost(self::ligne($veille, 800), 7200);

        self::assertNotNull($sansDecalage);
        self::assertNotNull($avecDecalage);
        self::assertSame('2026-08-02', $sansDecalage->date);
        self::assertSame(DayOfWeek::Sun, $sansDecalage->dayOfWeek);
        self::assertSame('2026-08-03', $avecDecalage->date);
        self::assertSame(DayOfWeek::Mon, $avecDecalage->dayOfWeek);
    }

    public function testUneJourneeInexploitableEstEcartee(): void
    {
        // Pas d'horodatage : on ne saurait pas de quel jour on parle.
        self::assertNull(ForecastDay::fromHost(['weather' => [['id' => 800]]], 0));

        // Code inconnu : deviner « couvert » aurait fait passer un trou pour
        // une prévision.
        self::assertNull(ForecastDay::fromHost(self::ligne(self::LUNDI, 42), 0));
    }

    public function testLesTemperaturesAbsentesRestentNulles(): void
    {
        $jour = ForecastDay::fromHost(['dt' => self::LUNDI, 'weather' => [['id' => 800]]], 0);

        self::assertNotNull($jour);
        self::assertNull($jour->tempMin);
        self::assertNull($jour->tempMax);
        self::assertSame(0, $jour->rainChance);
        self::assertSame('', $jour->summary);
    }

    /** `pop` va de 0 à 1 chez l'hôte ; l'écran parle en pourcentage. */
    #[DataProvider('probabilites')]
    public function testLaProbabilitePasseEnPourcentage(mixed $pop, int $attendu): void
    {
        $jour = ForecastDay::fromHost(
            ['dt' => self::LUNDI, 'weather' => [['id' => 500]], 'pop' => $pop],
            0,
        );

        self::assertNotNull($jour);
        self::assertSame($attendu, $jour->rainChance);
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function probabilites(): iterable
    {
        yield 'aucune' => [0, 0];
        yield 'un quart' => [0.25, 25];
        yield 'certaine' => [1, 100];
        yield 'au-dessus de un' => [1.4, 100];
        yield 'négative' => [-0.2, 0];
        yield 'absente' => [null, 0];
    }

    // ── La semaine ──────────────────────────────────────────────────────────

    /**
     * Huit journées arrivent, sept sont gardées.
     *
     * La huitième est le même jour de semaine que la première : la garder
     * aurait fait écraser la prévision de ce lundi-ci par celle du lundi
     * suivant.
     */
    public function testLaHuitiemeJourneeEstEcartee(): void
    {
        $lignes = [];
        for ($i = 0; $i < 8; ++$i) {
            $lignes[] = self::ligne(self::LUNDI + $i * self::JOUR, $i === 7 ? 600 : 800);
        }

        $prevision = WeatherForecast::fromHost(['daily' => $lignes], '2026-08-03');

        self::assertCount(7, $prevision->days);
        self::assertSame('2026-08-09', $prevision->days[6]->date);
        // Le lundi garde son soleil, pas la neige du lundi suivant.
        self::assertSame(WeatherKind::Sunny, $prevision->byDayOfWeek()[DayOfWeek::Mon->value]);
    }

    /**
     * Une prévision relue le lendemain porte encore la veille.
     *
     * L'appliquer aurait posé sur mardi prochain le temps de mardi dernier.
     */
    public function testLesJourneesPasseesSontJetees(): void
    {
        $prevision = WeatherForecast::fromHost([
            'daily' => [
                self::ligne(self::LUNDI - self::JOUR, 600),
                self::ligne(self::LUNDI, 800),
                self::ligne(self::LUNDI + self::JOUR, 500),
            ],
        ], '2026-08-03');

        self::assertCount(2, $prevision->days);
        self::assertSame('2026-08-03', $prevision->days[0]->date);
        self::assertArrayNotHasKey(DayOfWeek::Sun->value, $prevision->byDayOfWeek());
    }

    public function testLesJourneesSontRanguesParDate(): void
    {
        $prevision = WeatherForecast::fromHost([
            'daily' => [
                self::ligne(self::LUNDI + 2 * self::JOUR, 500),
                self::ligne(self::LUNDI, 800),
                self::ligne(self::LUNDI + self::JOUR, 600),
            ],
        ], '2026-08-03');

        self::assertSame(
            ['2026-08-03', '2026-08-04', '2026-08-05'],
            array_map(static fn (ForecastDay $j): string => $j->date, $prevision->days),
        );
    }

    /** Une date répétée par l'hôte : la première réponse fait foi. */
    public function testUneDateRepeteeNeCompteQuUneFois(): void
    {
        $prevision = WeatherForecast::fromHost([
            'daily' => [
                self::ligne(self::LUNDI, 800),
                self::ligne(self::LUNDI + 3600, 600),
            ],
        ], '2026-08-03');

        self::assertCount(1, $prevision->days);
        self::assertSame(WeatherKind::Sunny, $prevision->days[0]->kind);
    }

    public function testUneReponseVideDonneUnePrevisionVide(): void
    {
        self::assertTrue(WeatherForecast::fromHost([], '2026-08-03')->isEmpty());
        self::assertTrue(WeatherForecast::fromHost(['daily' => 'rien'], '2026-08-03')->isEmpty());
        self::assertTrue(WeatherForecast::fromHost(['daily' => [['dt' => 1]]], '2026-08-03')->isEmpty());
    }

    public function testLeDecalageDeLaReponseSAppliqueATousLesJours(): void
    {
        $prevision = WeatherForecast::fromHost([
            'timezone' => 'Europe/Warsaw',
            'timezone_offset' => 7200,
            'daily' => [
                self::ligne(self::LUNDI - 2 * 3600, 800),
                self::ligne(self::LUNDI + self::JOUR - 2 * 3600, 500),
            ],
        ], '2026-08-03');

        self::assertSame('Europe/Warsaw', $prevision->timezone);
        self::assertSame(['2026-08-03', '2026-08-04'], array_map(
            static fn (ForecastDay $j): string => $j->date,
            $prevision->days,
        ));
    }

    /**
     * Un jour absent de la prévision ne figure PAS dans le tableau rendu.
     *
     * L'appelant garde alors la saisie en place, au lieu d'y poser un
     * « couvert » qui passerait pour une prévision.
     */
    public function testUnJourAbsentNApparaitPas(): void
    {
        $prevision = WeatherForecast::of([
            ForecastDay::of('2026-08-03', DayOfWeek::Mon, WeatherKind::Rain),
            ForecastDay::of('2026-08-05', DayOfWeek::Wed, WeatherKind::Sunny),
        ]);

        $parJour = $prevision->byDayOfWeek();

        self::assertSame([DayOfWeek::Mon->value, DayOfWeek::Wed->value], array_keys($parJour));
        self::assertSame(WeatherKind::Rain, $parJour[DayOfWeek::Mon->value]);
    }

    public function testOnRetrouveUneJourneeParSaDate(): void
    {
        $prevision = WeatherForecast::of([
            ForecastDay::of('2026-08-03', DayOfWeek::Mon, WeatherKind::Rain),
        ]);

        self::assertNotNull($prevision->on('2026-08-03'));
        self::assertNull($prevision->on('2026-08-04'));
    }
}
