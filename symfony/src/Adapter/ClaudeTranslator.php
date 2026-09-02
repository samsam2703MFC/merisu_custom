<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Messages\TextBlock;
use Anthropic\RequestOptions;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Store\AiCredentialStore;
use Psr\Http\Client\ClientInterface;

/**
 * Traduction assistée par l'API Claude.
 *
 * ── Le format de sortie est CONTRAINT, pas demandé
 *
 * Le schéma JSON passé en `outputConfig` ne décrit pas un vœu : l'API refuse
 * de produire autre chose. Sans lui, une réponse polie du genre « Voici les
 * traductions : … » se serait glissée un jour sur trois dans le nom d'un
 * produit, et personne ne l'aurait vue avant l'impression de l'étiquette.
 *
 * Le schéma est bâti à la demande, avec exactement les champs envoyés et
 * exactement les langues manquantes : la réponse ne peut donc ni en oublier
 * un, ni en inventer un autre.
 *
 * ── Ce qui n'est PAS envoyé
 *
 * Des libellés de produits, et rien d'autre. Ni comptages, ni codes PIN, ni
 * identités : la traduction ne voit que ce qui finira imprimé sur une
 * barquette et affiché en vitrine.
 */
final class ClaudeTranslator implements AutoTranslatorInterface
{
    /**
     * Le modèle par défaut, surchargeable par `MERISU_AI_MODEL`.
     *
     * Le réglage existe parce que le bon choix dépend de la boutique : une
     * carte qui change chaque semaine appelle un modèle rapide, une carte
     * stable préfère le meilleur rendu. Le défaut vise la qualité — c'est
     * l'étiquette d'un produit alimentaire, et un allergène mal traduit n'est
     * pas une coquille.
     */
    public const DEFAULT_MODEL = 'claude-opus-5';

    /**
     * Plafond de sortie.
     *
     * Trois champs × trois langues de libellés courts tiennent très en deçà ;
     * la marge couvre les ingrédients, qui vont jusqu'à 600 caractères par
     * langue, et les langues qui s'allongent en traduction.
     */
    private const MAX_TOKENS = 4096;

    /**
     * Vingt secondes, et non les dix minutes du défaut.
     *
     * Un administrateur attend devant son écran : au-delà d'une vingtaine de
     * secondes il recharge la page, et l'appel continue sans personne pour en
     * lire le résultat. Mieux vaut échouer visiblement et le lui dire.
     */
    private const TIMEOUT_SECONDS = 20.0;

    private ?Client $client = null;

    /**
     * @param string               $apiKey      clé d'environnement — vide = non configuré
     * @param string               $model       modèle d'environnement
     * @param ClientInterface|null $transporter client HTTP imposé (tests)
     * @param AiCredentialStore|null $store      la saisie d'écran, qui l'emporte
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $apiKey = '',
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly ?ClientInterface $transporter = null,
        private readonly ?AiCredentialStore $store = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->effectiveKey()) !== '';
    }

    /**
     * La clé en vigueur : l'écran d'abord, l'environnement ensuite.
     *
     * Le store n'est pas là dans les tests, qui passent la clé au constructeur.
     * En production, une clé saisie à l'écran survit à une réinstallation qui
     * repart sur un `.env.local` neuf ; la variable d'environnement reste le
     * recours quand rien n'a été saisi.
     */
    private function effectiveKey(): string
    {
        $saisis = $this->store?->stored();

        return $saisis !== null && $saisis->isComplete() ? $saisis->apiKey : $this->apiKey;
    }

    /** Le modèle en vigueur : celui de l'écran s'il a été choisi, sinon l'environnement. */
    private function effectiveModel(): string
    {
        return $this->store?->stored()?->model ?? $this->model;
    }

    public function translate(array $texts, Locale $source, array $targets, string $context): array
    {
        if (!$this->isConfigured()) {
            throw new TranslationUnavailable('admin.translate.notConfigured');
        }

        if ($texts === [] || $targets === []) {
            return [];
        }

        try {
            $message = $this->client()->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: [['role' => 'user', 'content' => self::prompt($texts, $source, $targets, $context)]],
                model: $this->effectiveModel(),
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::schema($texts, $targets)]],
                system: self::SYSTEM,
                requestOptions: RequestOptions::with(timeout: self::TIMEOUT_SECONDS),
            );
        } catch (APIStatusException $e) {
            // Une clé refusée n'est pas une panne : elle ne se réparera pas en
            // recliquant, et l'écran doit dire laquelle des deux c'est.
            throw new TranslationUnavailable(
                in_array($e->status, [401, 403], true) ? 'admin.translate.badKey' : 'admin.translate.hostRefused',
                previous: $e,
            );
        } catch (AnthropicException | \Psr\Http\Client\ClientExceptionInterface $e) {
            throw new TranslationUnavailable('admin.translate.unreachable', previous: $e);
        }

        // `content` est POLYMORPHE : un bloc de réflexion peut précéder le
        // texte. Prendre `content[0]` marchait tant que le modèle n'y mettait
        // rien d'autre, et cessait de marcher sans prévenir.
        $json = '';
        foreach ($message->content as $bloc) {
            if ($bloc instanceof TextBlock) {
                $json .= $bloc->text;
            }
        }

        return self::decode($json, $texts, $targets);
    }

    /**
     * Le client, construit au premier appel.
     *
     * Pas dans le constructeur : le conteneur instancie ce service sur toutes
     * les pages d'administration, et la découverte du client HTTP n'a pas à se
     * payer sur des écrans qui ne traduisent rien.
     */
    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->effectiveKey(),
            requestOptions: $this->transporter !== null
                ? RequestOptions::with(transporter: $this->transporter)
                : null,
        );
    }

    private const SYSTEM = <<<'TXT'
        Tu traduis les libellés d'une pâtisserie artisanale italienne (tiramisus,
        desserts en verrine, boissons) pour ses écrans de vente et ses étiquettes.

        Règles :
        - Rends une traduction que le client lit sur l'étiquette, pas une glose.
          Pas d'explication, pas de reformulation, pas de guillemets ajoutés.
        - Un nom de produit reste court : il s'affiche sur une ligne de tablette.
        - Les noms propres et les termes italiens consacrés (tiramisù, mascarpone,
          savoiardi, amaretto) ne se traduisent pas.
        - Les allergènes suivent la dénomination réglementaire de la langue
          visée. C'est une mention légale : dans le doute, reste littéral.
        - Conserve la ponctuation, les majuscules et les séparateurs de la
          source (virgules d'une liste d'ingrédients, retours à la ligne).
        TXT;

    /**
     * @param array<string,string> $texts
     * @param list<Locale>         $targets
     */
    private static function prompt(array $texts, Locale $source, array $targets, string $context): string
    {
        $langues = implode(', ', array_map(static fn (Locale $l): string => $l->value, $targets));

        $lignes = [];
        foreach ($texts as $champ => $texte) {
            $lignes[] = $champ . ' : ' . $texte;
        }

        return sprintf(
            "Contexte : %s\nLangue source : %s\nLangues à produire : %s\n\nTextes :\n%s",
            $context,
            $source->value,
            $langues,
            implode("\n", $lignes),
        );
    }

    /**
     * Le schéma exact de la réponse attendue.
     *
     * `additionalProperties: false` partout et `required` complet : c'est ce
     * qui rend le décodage plus bas si court — il n'a pas à se défendre contre
     * une clé en trop ou une langue manquante.
     *
     * @param array<string,string> $texts
     * @param list<Locale>         $targets
     *
     * @return array<string,mixed>
     */
    private static function schema(array $texts, array $targets): array
    {
        $parLangue = [];
        foreach ($targets as $locale) {
            $parLangue[$locale->value] = ['type' => 'string'];
        }
        $languesRequises = array_keys($parLangue);

        $proprietes = [];
        foreach (array_keys($texts) as $champ) {
            $proprietes[$champ] = [
                'type' => 'object',
                'properties' => $parLangue,
                'required' => $languesRequises,
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'object',
            'properties' => $proprietes,
            'required' => array_keys($proprietes),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string,string> $texts
     * @param list<Locale>         $targets
     *
     * @return array<string,array<string,string>>
     */
    private static function decode(string $json, array $texts, array $targets): array
    {
        try {
            /** @var mixed $brut */
            $brut = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TranslationUnavailable('admin.translate.badAnswer', previous: $e);
        }

        if (!is_array($brut)) {
            throw new TranslationUnavailable('admin.translate.badAnswer');
        }

        // On ne relit que ce qu'on a demandé. Le schéma l'interdit déjà, mais
        // ce module écrit en base : une réponse conforme au schéma reste une
        // réponse venue d'ailleurs.
        $sortie = [];
        foreach (array_keys($texts) as $champ) {
            $valeurs = $brut[$champ] ?? null;
            if (!is_array($valeurs)) {
                continue;
            }

            foreach ($targets as $locale) {
                $texte = $valeurs[$locale->value] ?? null;
                if (is_string($texte) && trim($texte) !== '') {
                    $sortie[$champ][$locale->value] = trim($texte);
                }
            }
        }

        if ($sortie === []) {
            throw new TranslationUnavailable('admin.translate.badAnswer');
        }

        return $sortie;
    }
}
