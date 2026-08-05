<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une consigne de la note du jour, affichée en tête du menu des tâches.
 *
 * Le Tiramishow, le Ciao et le Grazie : ce sont des consignes de MARQUE, et
 * une marque les fait évoluer. Elles vivaient dans les fichiers de traduction,
 * ce qui imposait un déploiement pour changer une phrase — autant dire qu'elles
 * n'auraient jamais changé. Elles se rédigent maintenant en administration,
 * dans les quatre langues, comme les points de check-list.
 *
 * Le corps accepte les retours à la ligne : « Ciao à l'entrée » et « Grazie au
 * départ » sont deux gestes, et les écrire l'un sous l'autre les rend plus
 * lisibles qu'un paragraphe.
 */
final readonly class DayNote
{
    /**
     * @param array<string,string> $heading Intertitre par langue
     * @param array<string,string> $body    Texte par langue
     */
    public function __construct(
        public string $id,
        public array $heading,
        public array $body,
        public int $sortOrder,
        public bool $active,
    ) {
    }

    /** Intertitre dans la langue demandée, avec repli en cascade. */
    public function headingText(Locale $locale, Locale $default = Locale::Fr): string
    {
        return self::pick($this->heading, $locale, $default) ?? $this->id;
    }

    /** Texte dans la langue demandée, avec repli en cascade. */
    public function bodyText(Locale $locale, Locale $default = Locale::Fr): string
    {
        return self::pick($this->body, $locale, $default) ?? '';
    }

    /** Vrai si rien n'est rédigé : une consigne vide ne s'affiche pas. */
    public function isEmpty(): bool
    {
        return self::pick($this->heading, Locale::Fr) === null
            && self::pick($this->body, Locale::Fr) === null;
    }

    /**
     * Langue demandée → langue par défaut → première renseignée.
     *
     * Le même repli que les produits et la check-list : une boutique polonaise
     * dont l'administrateur n'a rempli que le français doit lire le français,
     * jamais une ligne vide qui laisserait croire à une consigne supprimée.
     *
     * @param array<string,string> $valeurs
     */
    private static function pick(array $valeurs, Locale $locale, Locale $default = Locale::Fr): ?string
    {
        foreach ([$locale->value, $default->value] as $candidat) {
            $valeur = trim($valeurs[$candidat] ?? '');
            if ($valeur !== '') {
                return $valeur;
            }
        }

        foreach (Locale::all() as $repli) {
            $valeur = trim($valeurs[$repli->value] ?? '');
            if ($valeur !== '') {
                return $valeur;
            }
        }

        return null;
    }

    public function with(mixed ...$changes): self
    {
        return new self(
            $changes['id'] ?? $this->id,
            $changes['heading'] ?? $this->heading,
            $changes['body'] ?? $this->body,
            $changes['sortOrder'] ?? $this->sortOrder,
            $changes['active'] ?? $this->active,
        );
    }
}
