<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Un poste RH — vendeur, chef de rang, responsable — et ses NIVEAUX.
 *
 * ⚠️ À ne pas confondre avec le poste de TRAVAIL (« stanowisko », ws-1, ws-2).
 * L'un est l'endroit où l'on compte, l'autre la fonction qu'on occupe. Une
 * même personne tient deux postes de travail dans la journée sans changer de
 * poste RH, et deux vendeurs au même comptoir peuvent être à des niveaux
 * différents. Les mélanger aurait fait dépendre le plan de production d'une
 * promotion.
 *
 * Les niveaux sont ORDONNÉS : « débutant » précède « confirmé », et c'est cet
 * ordre — non l'ordre alphabétique, non l'ordre de création — qui dit la
 * progression. Il correspond au `level_order` de l'hôte.
 */
final readonly class JobPosition
{
    /** @param list<PositionLevel> $levels */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public int $sortOrder,
        public array $levels = [],
    ) {
    }

    public function level(string $levelId): ?PositionLevel
    {
        foreach ($this->levels as $niveau) {
            if ($niveau->id === $levelId) {
                return $niveau;
            }
        }

        return null;
    }

    /**
     * Un poste sans niveau ne peut être affecté à personne.
     *
     * L'affectation porte TOUJOURS un couple poste + niveau — c'est ce
     * qu'attend l'hôte (`position_id`, `level_id`). Un poste créé mais sans
     * niveau est donc une fiche à finir, et l'écran doit le dire plutôt que de
     * le proposer dans une liste où le choisir ne mènerait à rien.
     */
    public function isAssignable(): bool
    {
        return $this->levels !== [];
    }
}
