<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Domain\TaskTile;

/**
 * Consultant — provient du module « Consultant / Stanowisko » existant.
 *
 * Ces champs alimentent la fiche profil. Ils sont TOUS fournis par le module
 * existant : ce module ne les stocke pas et ne les modifie pas.
 *
 * TODO INTÉGRATION : compléter la correspondance dans votre implémentation de
 * `ConsultantServiceInterface`. Les champs facultatifs peuvent rester vides,
 * la fiche profil masque d'elle-même les lignes non renseignées.
 */
final readonly class Consultant
{
    /**
     * @param list<string> $shops        boutiques auxquelles le consultant est rattaché
     * @param list<string> $workstations postes auxquels il peut être affecté
     */
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public Role $role,
        /** Poste pré-affecté, si le module existant en fournit un. */
        public ?string $defaultWorkstationId,
        public bool $active,
        public ?string $email = null,
        /** Code PIN, affiché masqué sur la fiche profil. */
        public ?string $pin = null,
        public array $shops = [],
        public array $workstations = [],
        /** Langue préférée, si le module existant la connaît. */
        public ?Locale $locale = null,
        /**
         * Tuiles du menu ouvertes à cette personne.
         *
         * VIDE = toutes. Les fiches déjà en base n'en portent aucune, et lire
         * cela comme « aucun droit » aurait renvoyé toute la boutique sur un
         * menu vide dès la mise à jour. Restreindre est un geste délibéré.
         *
         * @var list<TaskTile>
         */
        public array $tiles = [],
    ) {
    }

    /**
     * Les boutiques de cette personne, ramenées à des IDENTIFIANTS.
     *
     * Le champ a longtemps porté du texte libre — « Merisù Centrum » — qui ne
     * désignait aucune fiche. Il porte maintenant des identifiants, mais les
     * anciennes valeurs sont encore en base et il serait malhonnête de les
     * faire disparaître en silence : une affectation posée il y a six mois
     * doit rester lisible.
     *
     * On accepte donc les deux, et l'on rapproche le texte du NOM d'une
     * boutique — insensible à la casse et aux espaces, parce que c'est
     * exactement ainsi que le champ libre a divergé.
     *
     * @param list<object{id: string, name: string}> $boutiques
     *
     * @return list<string>
     */
    public function shopIds(array $boutiques): array
    {
        $parId = [];
        $parNom = [];

        foreach ($boutiques as $boutique) {
            $parId[$boutique->id] = $boutique->id;
            $parNom[mb_strtolower(trim($boutique->name))] = $boutique->id;
        }

        $retenus = [];

        foreach ($this->shops as $valeur) {
            $texte = trim((string) $valeur);

            $id = $parId[$texte] ?? $parNom[mb_strtolower($texte)] ?? null;

            if ($id !== null && !\in_array($id, $retenus, true)) {
                $retenus[] = $id;
            }
        }

        return $retenus;
    }

    /**
     * Cette personne pilote-t-elle cette boutique ?
     *
     * L'ADMIN, oui, partout : c'est ce que le rôle veut dire. Un manager SANS
     * boutique affectée n'en pilote AUCUNE — et non « toutes ». La liste vide
     * signifie « on ne lui en a pas encore donné », pas « le réseau entier » ;
     * lire le vide comme une permission ouvrirait le réseau à quiconque est
     * promu manager avant qu'on ait rempli sa fiche.
     *
     * @param list<object{id: string, name: string}> $boutiques
     */
    public function managesShop(string $shopId, array $boutiques): bool
    {
        if ($this->role->isAdmin()) {
            return true;
        }

        return \in_array($shopId, $this->shopIds($boutiques), true);
    }

    /** Nom affiché : prénom + nom, avec repli sur l'identifiant. */
    public function displayName(): string
    {
        $name = trim($this->firstName . ' ' . $this->lastName);

        return $name !== '' ? $name : $this->id;
    }

    /** Initiales pour l'avatar, faute de photo fournie par le module existant. */
    public function initials(): string
    {
        $initials = mb_strtoupper(
            mb_substr(trim($this->firstName), 0, 1) . mb_substr(trim($this->lastName), 0, 1),
        );

        return $initials !== '' ? $initials : mb_strtoupper(mb_substr($this->id, 0, 2));
    }

    /**
     * Code PIN masqué : seuls les deux derniers chiffres restent lisibles.
     *
     * La fiche profil s'affiche sur une tablette posée au poste ; montrer le
     * code en clair par défaut l'exposerait à quiconque passe devant.
     */
    public function maskedPin(): string
    {
        if ($this->pin === null || $this->pin === '') {
            return '';
        }

        return str_repeat('•', max(0, mb_strlen($this->pin) - 2)) . mb_substr($this->pin, -2);
    }
}
