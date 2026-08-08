<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\Locale;

/**
 * Implémentation d'attente : elle ne traduit rien, et le dit.
 *
 * Utile pour une démonstration hors ligne, ou pour couper la fonction sans
 * toucher au code : il suffit de la brancher à la place de `ClaudeTranslator`
 * dans `config/services.yaml`. `isConfigured()` renvoie false, donc les écrans
 * ne proposent même pas le bouton.
 *
 * `ClaudeTranslator` sans clé se comporte déjà ainsi — celle-ci existe pour le
 * cas où l'on veut la certitude qu'AUCUN libellé ne sort de la boutique, même
 * si une clé traîne dans l'environnement.
 */
final class NullAutoTranslator implements AutoTranslatorInterface
{
    public function translate(array $texts, Locale $source, array $targets, string $context): array
    {
        throw new TranslationUnavailable('admin.translate.notConfigured');
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
