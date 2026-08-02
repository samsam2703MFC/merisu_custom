<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Produit — un des 8 emplacements génériques, renommables et activables en admin.
 *
 * Aucune valeur produit n'est codée dans l'application : tout vient de la base.
 */
final readonly class Product
{
    /**
     * @param array<string,string> $name Libellé par langue : ['fr' => 'Crème 1', 'pl' => …]
     */
    public function __construct(
        public string $id,
        /** Code stable PRODUIT_1 … PRODUIT_8, jamais affiché à l'utilisateur. */
        public string $code,
        public array $name,
        public string $unit,
        public bool $active,
        /** Facteur de perte : 0.05 = +5 % produits en plus. */
        public float $wasteFactor,
        public float $roundingStep,
        public RoundingMode $roundingMode,
        /** Référence du produit dans le module « Recette technique » existant. */
        public ?string $recipeRef,
        public int $sortOrder,
        /**
         * Unité ou contenant. Le mode conteneur change la saisie à l'écran,
         * jamais le calcul : la quantité reste décimale en base.
         */
        public CountMode $countMode = CountMode::Pieces,
    ) {
    }

    /**
     * Libellé dans la langue demandée, avec repli en cascade :
     * langue demandée → langue par défaut → première langue renseignée → code.
     */
    public function label(Locale $locale, Locale $default = Locale::Fr): string
    {
        foreach ([$locale->value, $default->value] as $candidate) {
            $value = trim($this->name[$candidate] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        foreach (Locale::all() as $fallback) {
            $value = trim($this->name[$fallback->value] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return $this->code;
    }

    public function with(mixed ...$changes): self
    {
        return new self(
            $changes['id'] ?? $this->id,
            $changes['code'] ?? $this->code,
            $changes['name'] ?? $this->name,
            $changes['unit'] ?? $this->unit,
            $changes['active'] ?? $this->active,
            $changes['wasteFactor'] ?? $this->wasteFactor,
            $changes['roundingStep'] ?? $this->roundingStep,
            $changes['roundingMode'] ?? $this->roundingMode,
            \array_key_exists('recipeRef', $changes) ? $changes['recipeRef'] : $this->recipeRef,
            $changes['sortOrder'] ?? $this->sortOrder,
            $changes['countMode'] ?? $this->countMode,
        );
    }
}
