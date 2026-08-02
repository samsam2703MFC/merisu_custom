<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Un point de la check-list.
 *
 * Comme pour les produits, AUCUN libellé n'est codé dans l'application : les
 * points se créent, se renomment et se désactivent en administration. Une
 * cuisine n'a pas les mêmes contrôles qu'une autre, et ils changent avec les
 * saisons ; les figer dans le code imposerait un redéploiement à chaque
 * ajustement.
 */
final readonly class ChecklistItem
{
    /**
     * @param array<string,string> $label Libellé par langue : ['fr' => 'Vitrine essuyée', 'pl' => …]
     */
    public function __construct(
        public string $id,
        public ChecklistSection $section,
        public array $label,
        public int $sortOrder,
        public bool $active,
        /**
         * Point bloquant : sa case doit être cochée pour que la check-list du
         * volet soit considérée comme faite. Les autres restent indicatifs.
         */
        public bool $required = true,
    ) {
    }

    /**
     * Libellé dans la langue demandée, avec le même repli en cascade que les
     * produits : langue demandée → langue par défaut → première renseignée.
     */
    public function text(Locale $locale, Locale $default = Locale::Fr): string
    {
        foreach ([$locale->value, $default->value] as $candidate) {
            $valeur = trim($this->label[$candidate] ?? '');
            if ($valeur !== '') {
                return $valeur;
            }
        }

        foreach (Locale::all() as $repli) {
            $valeur = trim($this->label[$repli->value] ?? '');
            if ($valeur !== '') {
                return $valeur;
            }
        }

        // Plutôt qu'une ligne vide, incompréhensible au poste : l'identifiant
        // signale à l'administrateur qu'un libellé manque.
        return $this->id;
    }

    public function with(mixed ...$changes): self
    {
        return new self(
            $changes['id'] ?? $this->id,
            $changes['section'] ?? $this->section,
            $changes['label'] ?? $this->label,
            $changes['sortOrder'] ?? $this->sortOrder,
            $changes['active'] ?? $this->active,
            $changes['required'] ?? $this->required,
        );
    }
}
