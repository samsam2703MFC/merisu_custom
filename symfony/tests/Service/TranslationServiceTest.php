<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Service;

use Merisu\Inventory\Adapter\AutoTranslatorInterface;
use Merisu\Inventory\Adapter\TranslationUnavailable;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Service\TranslationService;
use PHPUnit\Framework\TestCase;

/**
 * Le compte rendu, surtout.
 *
 * L'administration ne montre qu'une langue : après un clic sur « Traduire »,
 * l'écran est exactement le même qu'avant. Ce que le service RAPPORTE est donc
 * la seule chose que l'administrateur verra du travail accompli.
 */
final class TranslationServiceTest extends TestCase
{
    /**
     * Traducteur de laboratoire : rend « <LANGUE>::<champ> » pour tout ce
     * qu'on lui demande, et retient la demande.
     *
     * @param array<string,array<string,string>>|null $forcee réponse imposée
     */
    private static function traducteur(?array $forcee = null, ?object $journal = null): AutoTranslatorInterface
    {
        return new class($forcee, $journal) implements AutoTranslatorInterface {
            /** @param array<string,array<string,string>>|null $forcee */
            public function __construct(private ?array $forcee, private ?object $journal)
            {
            }

            public function translate(array $texts, Locale $source, array $targets, string $context): array
            {
                if ($this->journal !== null) {
                    $this->journal->texts = $texts;
                    $this->journal->source = $source;
                    $this->journal->targets = $targets;
                }

                if ($this->forcee !== null) {
                    return $this->forcee;
                }

                $sortie = [];
                foreach (array_keys($texts) as $champ) {
                    foreach ($targets as $locale) {
                        $sortie[$champ][$locale->value] = strtoupper($locale->value) . '::' . $champ;
                    }
                }

                return $sortie;
            }

            public function isConfigured(): bool
            {
                return true;
            }
        };
    }

    // ── Rien à faire ────────────────────────────────────────────────────────

    /**
     * Une fiche complète ne consomme AUCUN appel : c'est un service facturé,
     * et rouvrir une fiche pour corriger son arrondi ne doit rien coûter.
     */
    public function testUneFicheCompleteNAppellePersonne(): void
    {
        $journal = new \stdClass();
        $journal->texts = null;

        $resultat = (new TranslationService(self::traducteur(null, $journal)))->complete(
            ['name' => ['fr' => 'Tiramisu', 'pl' => 'Tiramisu', 'it' => 'Tiramisù', 'es' => 'Tiramisú']],
            Locale::Fr,
            'un dessert',
        );

        self::assertNull($journal->texts);
        self::assertNull($resultat['source']);
        self::assertSame([], $resultat['written']);
    }

    /** Les champs sont rendus INCHANGÉS : l'appelant n'a rien à enregistrer. */
    public function testSansRienAFaireLesChampsSontRendusIntacts(): void
    {
        $champs = ['name' => [], 'allergens' => []];

        self::assertSame($champs, (new TranslationService(self::traducteur()))
            ->complete($champs, Locale::Fr, 'un dessert')['fields']);
    }

    // ── Ce qui est écrit ────────────────────────────────────────────────────

    public function testLesTrousSontComblesEtLeResteIntact(): void
    {
        $resultat = (new TranslationService(self::traducteur()))->complete([
            'name' => ['fr' => 'Tiramisu classique', 'pl' => 'Tiramisu babci'],
            'allergens' => ['fr' => 'Lait, œufs'],
        ], Locale::Fr, 'un dessert');

        self::assertSame(Locale::Fr, $resultat['source']);
        self::assertSame('Tiramisu babci', $resultat['fields']['name']['pl']);
        self::assertSame('IT::name', $resultat['fields']['name']['it']);
        self::assertSame('PL::allergens', $resultat['fields']['allergens']['pl']);
        self::assertSame('Lait, œufs', $resultat['fields']['allergens']['fr']);
    }

    public function testLeCompteRenduNommeLesLanguesEcrites(): void
    {
        $resultat = (new TranslationService(self::traducteur()))->complete(
            ['name' => ['fr' => 'Tiramisu', 'pl' => 'Tiramisu klasyczne']],
            Locale::Fr,
            'un dessert',
        );

        self::assertSame([Locale::It, Locale::Es], $resultat['written']);
        self::assertSame([], $resultat['missing']);
    }

    /**
     * Une langue déjà écrite n'est pas « écrite » une seconde fois : la
     * signaler aurait laissé croire qu'on venait de remplacer le travail de
     * quelqu'un.
     */
    public function testUneLangueDejaEcriteNEstPasComptee(): void
    {
        $resultat = (new TranslationService(self::traducteur()))->complete(
            ['name' => ['fr' => 'Tiramisu', 'pl' => 'Tiramisu babci', 'it' => 'Tiramisù', 'es' => 'Tiramisú']],
            Locale::Fr,
            'un dessert',
        );

        // Rien ne manque : aucun appel, aucune langue écrite.
        self::assertSame([], $resultat['written']);
    }

    // ── Ce qui manque encore ────────────────────────────────────────────────

    /** L'hôte n'a pas tout rendu : l'écran doit le dire, pas annoncer une fiche complète. */
    public function testUneLangueNonRendueEstSignaleeCommeManquante(): void
    {
        $resultat = (new TranslationService(self::traducteur([
            'name' => ['pl' => 'Tiramisu klasyczne'],
        ])))->complete(['name' => ['fr' => 'Tiramisu']], Locale::Fr, 'un dessert');

        self::assertSame([Locale::Pl], $resultat['written']);
        self::assertSame([Locale::It, Locale::Es], $resultat['missing']);
    }

    /**
     * Un produit SANS allergènes n'a rien à traduire de ce côté-là. Le compter
     * comme manquant aurait signalé un trou dans les quatre langues d'une
     * fiche sans défaut, à chaque traduction.
     */
    public function testUnChampVideDansLaSourceNeManqueDeRien(): void
    {
        $resultat = (new TranslationService(self::traducteur()))->complete([
            'name' => ['fr' => 'Tiramisu'],
            'allergens' => [],
        ], Locale::Fr, 'un dessert');

        self::assertSame([], $resultat['missing']);
        self::assertSame([], $resultat['fields']['allergens']);
    }

    // ── Panne ───────────────────────────────────────────────────────────────

    /**
     * Le service ne rattrape pas : c'est le contrôleur qui sait sur quel écran
     * on est, et donc quoi en dire.
     */
    public function testUnePanneRemonteTelleQuelle(): void
    {
        $traducteur = new class implements AutoTranslatorInterface {
            public function translate(array $texts, Locale $source, array $targets, string $context): array
            {
                throw new TranslationUnavailable('admin.translate.unreachable');
            }

            public function isConfigured(): bool
            {
                return true;
            }
        };

        $this->expectException(TranslationUnavailable::class);

        (new TranslationService($traducteur))->complete(['name' => ['fr' => 'Tiramisu']], Locale::Fr, 'x');
    }
}
