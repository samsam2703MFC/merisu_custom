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
        /**
         * La check-list à laquelle ce point appartient — par IDENTIFIANT.
         *
         * C'était une énumération à trois valeurs ; les check-lists sont
         * devenues des données, et le point désigne la sienne comme une ligne
         * en désigne une autre : par sa clé. Les identifiants historiques
         * (OPENING, CLOSING, QUALITY) sont ceux des trois lignes amorcées, si
         * bien que les points déjà signés retrouvent leur volet sans
         * migration.
         */
        public string $checklistId,
        public array $label,
        public int $sortOrder,
        public bool $active,
        /**
         * Point bloquant : sa case doit être cochée pour que la check-list du
         * volet soit considérée comme faite. Les autres restent indicatifs.
         */
        public bool $required = true,
        /**
         * Le point exige-t-il une photo ?
         *
         * Réglé en administration, point par point : photographier une vitrine
         * a du sens, photographier « caisse ouverte » n'en a aucun, et exiger
         * la photo partout la ferait bâcler partout.
         */
        public bool $requiresPhoto = false,
        /**
         * L'heure à laquelle CE point est attendu, au format HH:MM.
         *
         * Le volet porte déjà une heure — l'ouverture à 8 h, la fermeture à
         * 22 h — mais tous ses points ne se font pas au même moment : on relève
         * la température des vitrines à l'ouverture, on vérifie la propreté du
         * labo une heure plus tard. Sans cette précision, l'ordre d'exécution
         * tenait dans la tête de qui avait l'habitude.
         *
         * Vide = « à l'heure du volet ». C'est le cas ordinaire, et une heure
         * imposée partout aurait obligé à en inventer une pour chaque point.
         */
        public string $executionTime = '',
    ) {
    }

    /** Ce point porte-t-il sa propre heure, distincte de celle du volet ? */
    public function hasExecutionTime(): bool
    {
        return trim($this->executionTime) !== '';
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
            $changes['checklistId'] ?? $this->checklistId,
            $changes['label'] ?? $this->label,
            $changes['sortOrder'] ?? $this->sortOrder,
            $changes['active'] ?? $this->active,
            $changes['required'] ?? $this->required,
            $changes['requiresPhoto'] ?? $this->requiresPhoto,
            $changes['executionTime'] ?? $this->executionTime,
        );
    }
}
