<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Adapter\AutoTranslatorInterface;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Translation;

/**
 * Complète les libellés manquants d'un jeu de champs multilingues.
 *
 * Le seul point d'entrée des écrans d'administration : ils lui donnent des
 * champs, ils en reçoivent les mêmes champs complétés et le COMPTE RENDU de ce
 * qui a été écrit. Ce compte rendu n'est pas décoratif — l'administration ne
 * montre qu'une langue, et sans lui personne ne saurait ce qui vient d'arriver
 * aux trois autres.
 *
 * Le service ne rattrape PAS `TranslationUnavailable` : c'est le contrôleur
 * qui décide quoi en dire, parce que c'est lui qui sait sur quel écran on est.
 */
final class TranslationService
{
    public function __construct(private readonly AutoTranslatorInterface $translator)
    {
    }

    public function isAvailable(): bool
    {
        return $this->translator->isConfigured();
    }

    /**
     * @param array<string,array<string,string>> $fields  champ => langue => texte
     * @param Locale                             $from    la langue de l'écran, source privilégiée
     * @param string                             $context ce que sont ces textes, en clair
     *
     * @return array{
     *     fields: array<string,array<string,string>>,
     *     written: list<Locale>,
     *     missing: list<Locale>,
     *     source: ?Locale,
     * }
     *
     * @throws \Merisu\Inventory\Adapter\TranslationUnavailable
     */
    public function complete(array $fields, Locale $from, string $context): array
    {
        $plan = Translation::plan($fields, $from);

        // Rien à faire : aucune source, ou déjà complet. On rend les champs
        // INCHANGÉS et une source nulle — l'appelant n'enregistre rien, et
        // n'écrit donc pas une ligne d'audit pour une non-modification.
        if ($plan['source'] === null) {
            return ['fields' => $fields, 'written' => [], 'missing' => [], 'source' => null];
        }

        $produit = $this->translator->translate(
            $plan['texts'],
            $plan['source'],
            $plan['targets'],
            $context,
        );

        $ecrites = [];
        $complets = $fields;

        foreach ($fields as $champ => $valeurs) {
            $avant = $valeurs;
            $complets[$champ] = Translation::fill($valeurs, $produit[$champ] ?? []);

            foreach ($plan['targets'] as $locale) {
                if (($complets[$champ][$locale->value] ?? null) !== ($avant[$locale->value] ?? null)) {
                    $ecrites[$locale->value] = $locale;
                }
            }
        }

        // Ce qui manque ENCORE, après coup : une langue qu'un champ n'a pas
        // obtenue reste à écrire à la main, et l'écran doit le dire plutôt que
        // d'annoncer une fiche complète.
        //
        // Seuls les champs ENVOYÉS sont examinés. Un produit sans allergènes
        // n'a rien à traduire de ce côté-là, et le compter comme manquant
        // aurait signalé un trou dans les quatre langues d'une fiche sans
        // défaut.
        $manquantes = [];
        foreach ($plan['targets'] as $locale) {
            foreach (array_keys($plan['texts']) as $champ) {
                if (in_array($locale, Translation::missing($complets[$champ]), true)) {
                    $manquantes[$locale->value] = $locale;
                    break;
                }
            }
        }

        return [
            'fields' => $complets,
            'written' => array_values($ecrites),
            'missing' => array_values($manquantes),
            'source' => $plan['source'],
        ];
    }
}
