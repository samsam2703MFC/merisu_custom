<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\MetricTarget;
use Merisu\Inventory\Domain\ShopMetric;
use Merisu\Inventory\Domain\TargetMonth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricTargetTest extends TestCase
{
    // ── Constitution ────────────────────────────────────────────────────────

    public function testUnObjectifPorteSesTroisSeuils(): void
    {
        $cible = MetricTarget::of('chiffre_affaires', 30000.0, 36000.0, 42000.0);

        self::assertNotNull($cible);
        self::assertSame('chiffre_affaires', $cible->metricKey);
        self::assertSame(42000.0, $cible->threshold3);
    }

    /** @return iterable<string, array{string, float, float, float}> */
    public static function saisiesInutilisables(): iterable
    {
        yield 'clé vide' => ['', 1.0, 2.0, 3.0];
        yield 'clé sans lettre ni chiffre' => ['—', 1.0, 2.0, 3.0];
        yield 'seuil infini' => ['ca', \INF, 2.0, 3.0];
        yield 'seuil NaN' => ['ca', 1.0, \NAN, 3.0];
    }

    #[DataProvider('saisiesInutilisables')]
    public function testUneSaisieInutilisableNeProduitAucunObjectif(string $cle, float $t1, float $t2, float $t3): void
    {
        self::assertNull(MetricTarget::of($cle, $t1, $t2, $t3));
    }

    /** La clé est nettoyée à la volée : elle voyagera dans une URL et un JSON. */
    public function testLaCleEstNettoyee(): void
    {
        $cible = MetricTarget::of("Chiffre d'affaires", 1.0, 2.0, 3.0);

        self::assertNotNull($cible);
        self::assertSame('chiffre_d_affaires', $cible->metricKey);
    }

    /** @return iterable<string, array{string, string}> */
    public static function clesBrutes(): iterable
    {
        yield 'accents' => ['Coût matière', 'cout_matiere'];
        yield 'espaces multiples' => ['  panier   moyen  ', 'panier_moyen'];
        yield 'ponctuation' => ['CA / jour', 'ca_jour'];
        yield 'déjà propre' => ['ticket_count', 'ticket_count'];
        yield "rien d'exploitable" => ['€€€', ''];
    }

    #[DataProvider('clesBrutes')]
    public function testLeNettoyageDeCle(string $brut, string $attendu): void
    {
        self::assertSame($attendu, ShopMetric::cleanKey($brut));
    }

    // ── L'ordre suit le sens de l'indicateur ────────────────────────────────

    /**
     * Un chiffre d'affaires monte, un temps d'attente descend. Imposer partout
     * un ordre croissant aurait obligé à saisir « moins de trois minutes »
     * comme une valeur plus grande que « moins de cinq ».
     */
    public function testLOrdreDependDuSensDeLIndicateur(): void
    {
        $vente = MetricTarget::of('ca', 30.0, 36.0, 42.0);
        $attente = MetricTarget::of('attente', 5.0, 4.0, 3.0);

        self::assertNotNull($vente);
        self::assertNotNull($attente);

        self::assertTrue($vente->isOrdered(lowerIsBetter: false));
        self::assertFalse($vente->isOrdered(lowerIsBetter: true));

        self::assertTrue($attente->isOrdered(lowerIsBetter: true));
        self::assertFalse($attente->isOrdered(lowerIsBetter: false));
    }

    public function testDesSeuilsEgauxRestentOrdonnes(): void
    {
        $plat = MetricTarget::of('ca', 10.0, 10.0, 10.0);

        self::assertNotNull($plat);
        self::assertTrue($plat->isOrdered(false));
        self::assertTrue($plat->isOrdered(true));
    }

    // ── Le seuil atteint ────────────────────────────────────────────────────

    /** @return iterable<string, array{float, int}> */
    public static function resultatsDeVente(): iterable
    {
        yield 'sous le premier' => [25000.0, 0];
        yield 'pile au premier' => [30000.0, 1];
        yield 'entre deux' => [33000.0, 1];
        yield 'au deuxième' => [36000.0, 2];
        yield 'au-dessus du troisième' => [50000.0, 3];
    }

    #[DataProvider('resultatsDeVente')]
    public function testLeSeuilAtteintSurUneVente(float $valeur, int $attendu): void
    {
        $cible = MetricTarget::of('ca', 30000.0, 36000.0, 42000.0);

        self::assertNotNull($cible);
        self::assertSame($attendu, $cible->reached($valeur, lowerIsBetter: false));
    }

    public function testLeSeuilAtteintSurUnIndicateurQuiDescend(): void
    {
        $cible = MetricTarget::of('attente', 5.0, 4.0, 3.0);

        self::assertNotNull($cible);
        self::assertSame(0, $cible->reached(6.0, true));
        self::assertSame(1, $cible->reached(5.0, true));
        self::assertSame(3, $cible->reached(2.0, true));
    }

    // ── La forme attendue par l'hôte ────────────────────────────────────────

    /**
     * Les noms sont ceux du contrat TF Buddy, et non ceux de nos propriétés.
     * C'est le seul endroit où les deux vocabulaires se rejoignent.
     */
    public function testLaLignePartAuFormatDeLHote(): void
    {
        $cible = MetricTarget::of('ca', 1.0, 2.0, 3.0);

        self::assertNotNull($cible);
        self::assertSame([
            'metric_key' => 'ca',
            'threshold_1' => 1.0,
            'threshold_2' => 2.0,
            'threshold_3' => 3.0,
        ], $cible->toHost());
    }

    public function testLeCorpsCompletPortLAnneeLeMoisEtLAuteur(): void
    {
        $mois = TargetMonth::of(2026, 8);
        $cible = MetricTarget::of('ca', 1.0, 2.0, 3.0);

        self::assertNotNull($mois);
        self::assertNotNull($cible);

        $corps = $mois->toHost([$cible], 42);

        self::assertSame(2026, $corps['year']);
        self::assertSame(8, $corps['month']);
        self::assertSame(42, $corps['author_id']);
        self::assertCount(1, $corps['targets']);
        self::assertSame('ca', $corps['targets'][0]['metric_key']);
    }

    /** `author_id` a un minimum de 1 dans le contrat : un 0 serait refusé. */
    public function testLAuteurNeDescendJamaisSousUn(): void
    {
        $mois = TargetMonth::of(2026, 8);

        self::assertNotNull($mois);
        self::assertSame(1, $mois->toHost([], 0)['author_id']);
        self::assertSame(1, $mois->toHost([], -5)['author_id']);
    }
}
