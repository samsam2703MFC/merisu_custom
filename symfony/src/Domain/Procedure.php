<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une procédure du manuel opératoire : un problème, et ce qu'on fait.
 *
 * ── Trois champs, et l'ordre compte
 *
 * Le TITRE nomme la situation en quelques mots — c'est lui qu'on parcourt du
 * regard quand on cherche. Le PROBLÈME décrit ce qu'on a sous les yeux, et
 * c'est ce qui permet de reconnaître sa situation dans la liste : « la crème
 * a tranché » se reconnaît, « procédure 14 » non. La SOLUTION dit quoi faire.
 *
 * Le problème n'est pas un ornement. Sans lui, deux pannes voisines portent
 * des titres presque identiques et l'on applique la mauvaise solution — c'est
 * la description qui départage.
 *
 * ── Les photos font le travail que le texte ne fait pas
 *
 * « Le bac est monté trop haut » ne se décrit pas ; il se montre. Une
 * procédure sans photo reste valable, mais celles qui décrivent un geste ou
 * un état de matière en demandent une.
 *
 * ── Quatre langues, comme tout le reste
 *
 * Le poste tourne en polonais, en italien, en espagnol. Une procédure rédigée
 * dans la seule langue de l'administrateur ne serait pas lue par ceux à qui
 * elle s'adresse. Le repli est le même que pour la note du jour : la langue
 * demandée, puis le français, puis n'importe quelle langue remplie — mieux
 * vaut une consigne en italien qu'une case vide devant une machine en panne.
 */
final readonly class Procedure
{
    /**
     * @param array<string,string> $title    Titre par langue
     * @param array<string,string> $problem  Description du problème par langue
     * @param array<string,string> $solution Marche à suivre par langue
     * @param list<string>         $photos   Chemins publics, dans l'ordre
     */
    public function __construct(
        public string $id,
        public array $title,
        public array $problem,
        public array $solution,
        public array $photos = [],
        /** Rayon libre : « Machine », « Hygiène », « Caisse »… */
        public string $topic = '',
        public int $sortOrder = 0,
        public bool $active = true,
    ) {
    }

    public function titleText(Locale $locale, Locale $default = Locale::Fr): string
    {
        return self::pick($this->title, $locale, $default) ?? $this->id;
    }

    public function problemText(Locale $locale, Locale $default = Locale::Fr): string
    {
        return self::pick($this->problem, $locale, $default) ?? '';
    }

    public function solutionText(Locale $locale, Locale $default = Locale::Fr): string
    {
        return self::pick($this->solution, $locale, $default) ?? '';
    }

    public function hasPhotos(): bool
    {
        return $this->photos !== [];
    }

    /**
     * La procédure est-elle utilisable telle quelle ?
     *
     * Un titre sans marche à suivre est une promesse non tenue : on l'ouvre en
     * pleine panne pour y trouver une page blanche. L'administration la
     * signale comme incomplète plutôt que de la publier au poste.
     */
    public function isUsable(Locale $locale = Locale::Fr): bool
    {
        return self::pick($this->title, $locale) !== null
            && self::pick($this->solution, $locale) !== null;
    }

    /**
     * Le repli en cascade : la langue demandée, le français, puis n'importe
     * laquelle. Mieux vaut une consigne en italien qu'une case vide devant une
     * machine en panne.
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
            $changes['title'] ?? $this->title,
            $changes['problem'] ?? $this->problem,
            $changes['solution'] ?? $this->solution,
            $changes['photos'] ?? $this->photos,
            $changes['topic'] ?? $this->topic,
            $changes['sortOrder'] ?? $this->sortOrder,
            $changes['active'] ?? $this->active,
        );
    }
}
