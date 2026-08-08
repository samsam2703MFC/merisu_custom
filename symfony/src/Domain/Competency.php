<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une compétence : « monter un tiramisu devant le client », « clôturer la caisse ».
 *
 * Rangée par catégorie et sous-catégorie, comme chez l'hôte
 * (`category_name`, `subcategory_name`) : une liste plate de quarante
 * compétences ne se lit pas, et c'est le regroupement qui permet de retrouver
 * celle qu'on cherche.
 *
 * `verificationMethod` dit COMMENT on constate qu'elle est acquise —
 * observation, quiz, mise en situation. Facultatif, et c'est voulu : une
 * boutique qui n'a pas encore formalisé ses vérifications doit pouvoir tenir
 * la liste quand même. Mais le champ existe, parce qu'une compétence qu'on ne
 * sait pas vérifier est une intention, pas une compétence.
 */
final readonly class Competency
{
    public function __construct(
        public string $id,
        public string $name,
        public string $category,
        public string $subcategory,
        public ?string $verificationMethod,
        public int $sortOrder = 0,
    ) {
    }

    /**
     * Regroupe une liste par catégorie puis sous-catégorie, dans l'ordre reçu.
     *
     * Le regroupement se fait ICI et non dans le gabarit : Twig ne conserve
     * pas une variable d'une itération à l'autre, et les intertitres se
     * répétaient à chaque ligne.
     *
     * @param list<self> $competencies
     *
     * @return array<string, array<string, list<self>>>
     */
    public static function group(array $competencies): array
    {
        $groupes = [];

        foreach ($competencies as $competence) {
            $groupes[$competence->category][$competence->subcategory][] = $competence;
        }

        return $groupes;
    }
}
