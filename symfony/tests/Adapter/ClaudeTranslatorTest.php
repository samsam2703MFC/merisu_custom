<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Adapter;

use Merisu\Inventory\Adapter\ClaudeTranslator;
use Merisu\Inventory\Adapter\TranslationUnavailable;
use Merisu\Inventory\Domain\Locale;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * L'adaptateur de traduction, SANS réseau.
 *
 * Le client HTTP est remplacé par un mannequin qui retient la requête et rend
 * une réponse écrite ici. C'est le seul moyen de vérifier ce qui compte
 * vraiment — la forme du corps envoyé et la lecture de ce qui revient — sans
 * dépendre d'un service tiers, d'une clé, et d'une réponse qui varie.
 */
final class ClaudeTranslatorTest extends TestCase
{
    /** Réponse d'API minimale portant le JSON demandé. */
    private static function reponse(string $json, int $statut = 200): ResponseInterface
    {
        $corps = json_encode([
            'id' => 'msg_essai',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'content' => [['type' => 'text', 'text' => $json]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ], \JSON_THROW_ON_ERROR);

        return new Response($statut, ['Content-Type' => 'application/json'], $corps);
    }

    private static function erreur(int $statut): ResponseInterface
    {
        return new Response($statut, ['Content-Type' => 'application/json'], json_encode([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
        ], \JSON_THROW_ON_ERROR));
    }

    /** @param list<ResponseInterface> $reponses */
    private static function transporteur(array $reponses, ?object $journal = null): ClientInterface
    {
        return new class($reponses, $journal) implements ClientInterface {
            /** @param list<ResponseInterface> $reponses */
            public function __construct(private array $reponses, private ?object $journal)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if ($this->journal !== null) {
                    $this->journal->request = $request;
                    $this->journal->body = (string) $request->getBody();
                }

                return array_shift($this->reponses) ?? new Response(500);
            }
        };
    }

    private static function traducteur(ClientInterface $transporteur): ClaudeTranslator
    {
        return new ClaudeTranslator('sk-ant-essai', 'claude-opus-5', $transporteur);
    }

    // ── Configuration ───────────────────────────────────────────────────────

    /**
     * Sans clé, la fonction s'éteint : les écrans ne proposent pas le bouton,
     * et aucun libellé ne part vers un service tiers.
     */
    public function testSansCleLeServiceSeDeclareNonConfigure(): void
    {
        self::assertFalse((new ClaudeTranslator(''))->isConfigured());
        self::assertFalse((new ClaudeTranslator('   '))->isConfigured());
        self::assertTrue((new ClaudeTranslator('sk-ant-essai'))->isConfigured());
    }

    public function testSansCleAucunAppelNEstTente(): void
    {
        $journal = new \stdClass();
        $journal->request = null;

        $traducteur = new ClaudeTranslator('', 'claude-opus-5', self::transporteur([], $journal));

        try {
            $traducteur->translate(['name' => 'Tiramisu'], Locale::Fr, [Locale::Pl], 'essai');
            self::fail('Une clé absente doit interrompre avant tout appel.');
        } catch (TranslationUnavailable $e) {
            self::assertSame('admin.translate.notConfigured', $e->getMessage());
        }

        self::assertNull($journal->request);
    }

    /** Rien à traduire : pas d'appel, et pas d'erreur non plus. */
    public function testUneDemandeVideNeCoutteRien(): void
    {
        $journal = new \stdClass();
        $journal->request = null;

        $traducteur = self::traducteur(self::transporteur([], $journal));

        self::assertSame([], $traducteur->translate([], Locale::Fr, [Locale::Pl], 'essai'));
        self::assertSame([], $traducteur->translate(['name' => 'Tiramisu'], Locale::Fr, [], 'essai'));
        self::assertNull($journal->request);
    }

    // ── La requête envoyée ──────────────────────────────────────────────────

    public function testLaRequetePorteLeSchemaExactDeLaReponseAttendue(): void
    {
        $journal = new \stdClass();

        self::traducteur(self::transporteur(
            [self::reponse('{"name":{"pl":"Tiramisu klasyczne","it":"Tiramisù classico"}}')],
            $journal,
        ))->translate(['name' => 'Tiramisu classique'], Locale::Fr, [Locale::Pl, Locale::It], 'un dessert');

        $envoi = json_decode($journal->body, true, 16, \JSON_THROW_ON_ERROR);

        self::assertSame('claude-opus-5', $envoi['model']);

        $schema = $envoi['output_config']['format']['schema'];
        self::assertSame('json_schema', $envoi['output_config']['format']['type']);
        self::assertSame(['name'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['pl', 'it'], array_keys($schema['properties']['name']['properties']));
        self::assertSame(['pl', 'it'], $schema['properties']['name']['required']);
        self::assertFalse($schema['properties']['name']['additionalProperties']);
    }

    /**
     * `temperature`, `top_p` et `budget_tokens` sont REFUSÉS par ce modèle —
     * une erreur 400, pas un réglage ignoré. Les envoyer par habitude aurait
     * fait échouer chaque traduction.
     */
    public function testLaRequeteNePorteAucunReglageRefuseParLeModele(): void
    {
        $journal = new \stdClass();

        self::traducteur(self::transporteur(
            [self::reponse('{"name":{"pl":"Tiramisu klasyczne"}}')],
            $journal,
        ))->translate(['name' => 'Tiramisu'], Locale::Fr, [Locale::Pl], 'un dessert');

        $envoi = json_decode($journal->body, true, 16, \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('temperature', $envoi);
        self::assertArrayNotHasKey('top_p', $envoi);
        self::assertArrayNotHasKey('top_k', $envoi);
        self::assertArrayNotHasKey('thinking', $envoi);
    }

    /** Les textes et le contexte voyagent ensemble : c'est le contexte qui décide du registre. */
    public function testLaRequetePorteLesTextesEtLeurContexte(): void
    {
        $journal = new \stdClass();

        self::traducteur(self::transporteur(
            [self::reponse('{"name":{"pl":"x"},"allergens":{"pl":"y"}}')],
            $journal,
        ))->translate(
            ['name' => 'Tiramisu classique', 'allergens' => 'Lait, œufs'],
            Locale::Fr,
            [Locale::Pl],
            'mentions d’allergènes',
        );

        $envoi = json_decode($journal->body, true, 16, \JSON_THROW_ON_ERROR);
        $texte = $envoi['messages'][0]['content'];

        self::assertStringContainsString('Tiramisu classique', $texte);
        self::assertStringContainsString('Lait, œufs', $texte);
        self::assertStringContainsString('mentions d’allergènes', $texte);
        self::assertStringContainsString('fr', $texte);
        self::assertNotSame('', $envoi['system']);
    }

    // ── La réponse lue ──────────────────────────────────────────────────────

    public function testLaReponseEstReplieeParChampPuisParLangue(): void
    {
        $sortie = self::traducteur(self::transporteur([self::reponse(
            '{"name":{"pl":"Tiramisu klasyczne","it":"Tiramisù classico"},'
            . '"allergens":{"pl":"Mleko, jaja","it":"Latte, uova"}}',
        )]))->translate(
            ['name' => 'Tiramisu classique', 'allergens' => 'Lait, œufs'],
            Locale::Fr,
            [Locale::Pl, Locale::It],
            'un dessert',
        );

        self::assertSame([
            'name' => ['pl' => 'Tiramisu klasyczne', 'it' => 'Tiramisù classico'],
            'allergens' => ['pl' => 'Mleko, jaja', 'it' => 'Latte, uova'],
        ], $sortie);
    }

    /**
     * Le contenu d'un message est POLYMORPHE : un bloc de réflexion peut
     * précéder le texte. Lire `content[0]` marchait tant que le modèle n'y
     * mettait rien d'autre, et cessait de marcher sans prévenir.
     */
    public function testUnBlocDeReflexionNeMasquePasLaReponse(): void
    {
        $corps = json_encode([
            'id' => 'msg_essai',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Tiramisù ne se traduit pas.', 'signature' => 'sig'],
                ['type' => 'text', 'text' => '{"name":{"pl":"Tiramisu klasyczne"}}'],
            ],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ], \JSON_THROW_ON_ERROR);

        $sortie = self::traducteur(self::transporteur([
            new Response(200, ['Content-Type' => 'application/json'], $corps),
        ]))->translate(['name' => 'Tiramisu'], Locale::Fr, [Locale::Pl], 'un dessert');

        self::assertSame(['name' => ['pl' => 'Tiramisu klasyczne']], $sortie);
    }

    /** Ce qu'on n'a pas demandé n'entre pas en base, schéma ou pas. */
    public function testCeQuiNAPasEteDemandeEstEcarte(): void
    {
        $sortie = self::traducteur(self::transporteur([self::reponse(
            '{"name":{"pl":"Tiramisu klasyczne","de":"Tiramisu","es":"Tiramisú"},"prix":{"pl":"10"}}',
        )]))->translate(['name' => 'Tiramisu'], Locale::Fr, [Locale::Pl], 'un dessert');

        self::assertSame(['name' => ['pl' => 'Tiramisu klasyczne']], $sortie);
    }

    public function testUneLangueRendueVideNEstPasRetenue(): void
    {
        $sortie = self::traducteur(self::transporteur([self::reponse(
            '{"name":{"pl":"  ","it":"Tiramisù"}}',
        )]))->translate(['name' => 'Tiramisu'], Locale::Fr, [Locale::Pl, Locale::It], 'un dessert');

        self::assertSame(['name' => ['it' => 'Tiramisù']], $sortie);
    }

    /** @return iterable<string, array{string}> */
    public static function reponsesInexploitables(): iterable
    {
        yield 'pas du JSON' => ['Voici les traductions : Tiramisu klasyczne'];
        yield 'JSON tronqué' => ['{"name":{"pl":'];
        yield 'une liste' => ['[]'];
        yield 'une chaîne' => ['"Tiramisu klasyczne"'];
        yield 'les bons champs, vides' => ['{"name":{}}'];
        yield 'aucun champ connu' => ['{"autre":{"pl":"x"}}'];
    }

    /**
     * Rien n'est écrit, et on le dit. Le pire aurait été d'écrire « Voici les
     * traductions : … » dans le nom d'un produit et de l'imprimer.
     */
    #[DataProvider('reponsesInexploitables')]
    public function testUneReponseInexploitableNEcritRien(string $json): void
    {
        $this->expectException(TranslationUnavailable::class);
        $this->expectExceptionMessage('admin.translate.badAnswer');

        self::traducteur(self::transporteur([self::reponse($json)]))
            ->translate(['name' => 'Tiramisu'], Locale::Fr, [Locale::Pl], 'un dessert');
    }

    // ── Refus de l'hôte ─────────────────────────────────────────────────────

    /**
     * Une clé refusée ne se réparera pas en recliquant : l'écran doit dire
     * laquelle des deux causes c'est.
     */
    public function testUneCleRefuseeSeDistingueDUnePanne(): void
    {
        $this->expectException(TranslationUnavailable::class);
        $this->expectExceptionMessage('admin.translate.badKey');

        self::traducteur(self::transporteur([self::erreur(401)]))
            ->translate(['name' => 'Tiramisu'], Locale::Fr, [Locale::Pl], 'un dessert');
    }

    public function testUnRefusDeFondSeDistingueDUneCleInvalide(): void
    {
        $this->expectException(TranslationUnavailable::class);
        $this->expectExceptionMessage('admin.translate.hostRefused');

        self::traducteur(self::transporteur([self::erreur(400)]))
            ->translate(['name' => 'Tiramisu'], Locale::Fr, [Locale::Pl], 'un dessert');
    }
}
