<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\TemperatureBand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemperatureBandTest extends TestCase
{
    /**
     * Les BORNES, et rien que les bornes.
     *
     * C'est là que tout se joue : une température pile sur la limite doit
     * tomber d'un côté et d'un seul. La borne basse est incluse, la haute
     * exclue — sans cette règle, 10 °C appartiendrait à deux tranches et le
     * résultat dépendrait de l'ordre dans lequel on les a écrites.
     */
    #[DataProvider('temperatures')]
    public function testChaqueTemperatureTombeDansUneSeuleTranche(float $celsius, TemperatureBand $attendue): void
    {
        self::assertSame($attendue, TemperatureBand::of($celsius));
    }

    /** @return iterable<string, array{float, TemperatureBand}> */
    public static function temperatures(): iterable
    {
        yield 'grand froid' => [-15.0, TemperatureBand::Freezing];
        yield 'juste sous zéro' => [-0.1, TemperatureBand::Freezing];
        yield 'zéro pile' => [0.0, TemperatureBand::Cold];
        yield 'juste sous dix' => [9.9, TemperatureBand::Cold];
        yield 'dix pile' => [10.0, TemperatureBand::Mild];
        yield 'juste sous dix-huit' => [17.9, TemperatureBand::Mild];
        yield 'dix-huit pile' => [18.0, TemperatureBand::Pleasant];
        yield 'juste sous vingt-cinq' => [24.9, TemperatureBand::Pleasant];
        yield 'vingt-cinq pile' => [25.0, TemperatureBand::Warm];
        yield 'juste sous trente' => [29.9, TemperatureBand::Warm];
        yield 'trente pile' => [30.0, TemperatureBand::Hot];
        yield 'canicule' => [41.0, TemperatureBand::Hot];
    }

    /**
     * Sans thermomètre, pas de tranche.
     *
     * Une prévision qui ne porte pas de température ne doit pas se voir
     * attribuer une tranche par défaut : elle appliquerait une correction que
     * rien ne justifie, sur toutes les journées dont l'hôte s'est tu.
     */
    public function testUneTemperatureAbsenteNaPasDeTranche(): void
    {
        self::assertNull(TemperatureBand::of(null));
        self::assertNull(TemperatureBand::of(NAN));
        self::assertNull(TemperatureBand::of(INF));
    }

    /** Les six tranches se suivent sans trou ni recouvrement. */
    public function testLesTranchesSeSuiventSansTrouNiRecouvrement(): void
    {
        $tranches = TemperatureBand::all();

        self::assertNull($tranches[0]->lowerBound(), 'la première n\'a pas de plancher');
        self::assertNull(end($tranches)->upperBound(), 'la dernière n\'a pas de plafond');

        foreach ($tranches as $rang => $tranche) {
            if ($rang === 0) {
                continue;
            }

            self::assertSame(
                $tranches[$rang - 1]->upperBound(),
                $tranche->lowerBound(),
                'la tranche ' . $tranche->value . ' doit commencer où la précédente s\'arrête',
            );
        }
    }

    /**
     * La correction de départ est NULLE, pour les six.
     *
     * Le pourcentage d'une tranche se mesure sur les ventes d'une boutique ;
     * l'inventer ici aurait fait produire selon une intuition de développeur,
     * avec l'autorité d'un réglage livré.
     */
    public function testAucuneCorrectionNEstLivreeParDefaut(): void
    {
        foreach (TemperatureBand::all() as $tranche) {
            self::assertSame(0.0, $tranche->defaultPercent(), $tranche->value);
        }
    }

    public function testUneValeurInconnueRetombeSurUneTrancheConnue(): void
    {
        self::assertSame(TemperatureBand::Mild, TemperatureBand::fromLoose('n\'importe quoi'));
        self::assertSame(TemperatureBand::Hot, TemperatureBand::fromLoose('hot'));
        self::assertSame(TemperatureBand::Mild, TemperatureBand::fromLoose(null));
    }
}
