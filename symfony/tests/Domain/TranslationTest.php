<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Translation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslationTest extends TestCase
{
    // ── Choix de la langue source ───────────────────────────────────────────

    public function testLaLangueDeLEcranEstLaSource(): void
    {
        $champs = ['name' => ['fr' => 'Tiramisu', 'pl' => 'Tiramisu klasyczne']];

        self::assertSame(Locale::Fr, Translation::source($champs, Locale::Fr));
        self::assertSame(Locale::Pl, Translation::source($champs, Locale::Pl));
    }

    /**
     * On saisit une fiche en polonais, puis on repasse l'écran en français :
     * abandonner ici aurait rendu le bouton inopérant pour la seule personne
     * qui en avait besoin.
     */
    public function testFauteDeSourceOnPrendLaLangueLaMieuxRemplie(): void
    {
        $champs = [
            'name' => ['pl' => 'Tiramisu klasyczne', 'it' => 'Tiramisù'],
            'ingredients' => ['pl' => 'Mascarpone, kawa'],
        ];

        self::assertSame(Locale::Pl, Translation::source($champs, Locale::Fr));
    }

    public function testUneFicheEntierementVideNAAucuneSource(): void
    {
        self::assertNull(Translation::source(['name' => [], 'ingredients' => []], Locale::Fr));
        self::assertNull(Translation::source([], Locale::Fr));
    }

    /** @return iterable<string, array{mixed}> */
    public static function valeursBlanches(): iterable
    {
        yield 'chaîne vide' => [''];
        yield 'espaces' => ['   '];
        yield 'saut de ligne' => ["\n"];
        yield 'nulle' => [null];
        yield 'nombre' => [12];
    }

    /** Un champ blanc n'est pas une source : « &nbsp; » ne traduit rien. */
    #[DataProvider('valeursBlanches')]
    public function testUnChampBlancNeSertPasDeSource(mixed $valeur): void
    {
        self::assertNull(Translation::source(['name' => ['fr' => $valeur]], Locale::Fr));
    }

    // ── Constitution du plan ────────────────────────────────────────────────

    public function testLePlanNEnvoieQueLesChampsRenseignesDansLaSource(): void
    {
        $plan = Translation::plan([
            'name' => ['fr' => 'Tiramisu classique'],
            'ingredients' => ['fr' => '  Mascarpone, café  '],
            'allergens' => ['fr' => ''],
        ], Locale::Fr);

        self::assertSame(Locale::Fr, $plan['source']);
        self::assertSame(['name' => 'Tiramisu classique', 'ingredients' => 'Mascarpone, café'], $plan['texts']);
        self::assertSame([Locale::Pl, Locale::It, Locale::Es], $plan['targets']);
    }

    /**
     * Une langue déjà écrite partout n'est pas demandée. Sans ce filtre,
     * rouvrir une fiche complète relançait un appel entier pour n'écrire
     * nulle part.
     */
    public function testUneLangueDejaCompleteNEstPasDemandee(): void
    {
        $plan = Translation::plan([
            'name' => ['fr' => 'Tiramisu', 'pl' => 'Tiramisu klasyczne', 'it' => 'Tiramisù'],
        ], Locale::Fr);

        self::assertSame([Locale::Es], $plan['targets']);
    }

    public function testUneFicheCompleteNeProduitAucunPlan(): void
    {
        $plan = Translation::plan([
            'name' => ['fr' => 'Tiramisu', 'pl' => 'Tiramisu', 'it' => 'Tiramisù', 'es' => 'Tiramisú'],
        ], Locale::Fr);

        self::assertNull($plan['source']);
        self::assertSame([], $plan['texts']);
        self::assertSame([], $plan['targets']);
    }

    public function testUneFicheVideNeProduitAucunPlan(): void
    {
        self::assertNull(Translation::plan(['name' => [], 'allergens' => []], Locale::Fr)['source']);
    }

    /**
     * Le champ vide DANS la source, mais rempli ailleurs, ne demande rien :
     * la source n'a rien à en dire, et l'inventer serait pire que le trou.
     */
    public function testUnChampVideDansLaSourceNeDemandeRien(): void
    {
        $plan = Translation::plan([
            'name' => ['fr' => 'Tiramisu', 'pl' => 'Tiramisu klasyczne', 'it' => 'Tiramisù', 'es' => 'Tiramisú'],
            'allergens' => ['pl' => 'Mleko'],
        ], Locale::Fr);

        self::assertNull($plan['source']);
    }

    // ── Ce qu'on écrit, et ce qu'on n'écrit pas ─────────────────────────────

    /**
     * LA règle. Une traduction produite par une machine ne remplace jamais
     * celle qu'un vendeur polonais a écrite en connaissant le produit.
     */
    public function testUneTraductionExistanteNEstJamaisRemplacee(): void
    {
        $sortie = Translation::fill(
            ['fr' => 'Tiramisu classique', 'pl' => 'Tiramisu babci'],
            ['pl' => 'Klasyczne tiramisu', 'it' => 'Tiramisù classico'],
        );

        self::assertSame('Tiramisu babci', $sortie['pl']);
        self::assertSame('Tiramisù classico', $sortie['it']);
        self::assertSame('Tiramisu classique', $sortie['fr']);
    }

    public function testUnePropositionBlancheNEcritRien(): void
    {
        $sortie = Translation::fill(['fr' => 'Tiramisu'], ['pl' => '   ', 'it' => '']);

        self::assertArrayNotHasKey('pl', $sortie);
        self::assertArrayNotHasKey('it', $sortie);
    }

    public function testLesEspacesDeBordureSontRetires(): void
    {
        self::assertSame('Tiramisù', Translation::fill([], ['it' => "  Tiramisù\n"])['it']);
    }

    /** Une langue hors des quatre du module n'entre pas en base. */
    public function testUneLangueInconnueEstEcartee(): void
    {
        $sortie = Translation::fill(['fr' => 'Tiramisu'], ['de' => 'Tiramisu', 'pl' => 'Tiramisu klasyczne']);

        self::assertSame(['fr' => 'Tiramisu', 'pl' => 'Tiramisu klasyczne'], $sortie);
    }

    public function testAucuneProposition(): void
    {
        self::assertSame(['fr' => 'Tiramisu'], Translation::fill(['fr' => 'Tiramisu'], []));
    }

    // ── Compte rendu ────────────────────────────────────────────────────────

    public function testLesLanguesManquantesSeListentDansLOrdreDuModule(): void
    {
        self::assertSame(
            [Locale::It, Locale::Es],
            Translation::missing(['fr' => 'Tiramisu', 'pl' => 'Tiramisu klasyczne']),
        );
    }

    public function testUnChampCompletNeManqueDeRien(): void
    {
        self::assertSame([], Translation::missing([
            'fr' => 'a', 'pl' => 'b', 'it' => 'c', 'es' => 'd',
        ]));
    }

    public function testUnChampVideManqueDesQuatre(): void
    {
        self::assertCount(4, Translation::missing([]));
    }
}
