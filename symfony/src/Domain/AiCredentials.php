<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * La clé de traduction assistée et le modèle visé.
 *
 * Même esprit que les identifiants météo : une valeur qu'on saisit à l'écran,
 * qu'on ne réaffiche jamais, et qui vaut par elle-même — sans clé, l'assistant
 * de traduction n'a rien à faire. Le modèle est un réglage, pas un remède : les
 * deux répondent à la même clé, et en changer ne répare pas un refus.
 */
final readonly class AiCredentials
{
    public const DEFAULT_MODEL = 'claude-opus-5';

    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey = '',
        public string $model = self::DEFAULT_MODEL,
        public bool $fromScreen = false,
    ) {
    }

    /** Une fiche ne vaut que par sa clé : le modèle a toujours un défaut. */
    public function isComplete(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /** Le modèle, ramené à un défaut connu quand le champ est laissé vide. */
    public static function cleanModel(mixed $model): string
    {
        $model = is_string($model) ? trim($model) : '';

        return $model === '' ? self::DEFAULT_MODEL : $model;
    }
}
