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
