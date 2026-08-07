<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Adapter\Consultant;
use Merisu\Inventory\Adapter\Workstation;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Store\ConsultantStore;

/**
 * L'équipe de départ d'une boutique, définie en UN seul endroit.
 *
 * Elle était décrite dans la commande d'installation. Une seconde commande a
 * eu besoin de la même liste pour la réappliquer à un site déjà installé, et
 * deux copies d'une même vérité finissent toujours par diverger : on renomme
 * quelqu'un d'un côté, l'autre continue de créer l'ancien nom.
 *
 * Deux personnes, parce que cette application sert un point de vente et non un
 * réseau : une qui administre, une qui vend. Les suivantes s'ajoutent dans
 * Admin ▸ Équipe, sans redéploiement.
 */
final readonly class TeamInstaller
{
    /** Postes de travail de départ. */
    private const POSTES = [
        ['ws-1', 'Stanowisko 1'],
        ['ws-2', 'Stanowisko 2'],
    ];

    public function __construct(
        private ConsultantStore $team,
        private PinHasher $hasher,
        private string $adminPin,
        private string $consultant1Pin,
    ) {
    }

    /**
     * Crée l'équipe si elle n'existe pas encore.
     *
     * Appelée à chaque déploiement : elle ne doit donc RIEN écraser. Un site en
     * service a renommé ses vendeurs et changé leurs codes, et les remettre aux
     * valeurs de configuration à chaque mise à jour serait une catastrophe
     * silencieuse — les codes de l'équipe redeviendraient publics sans que
     * personne ne s'en aperçoive.
     *
     * @return list<string> ce qui a été fait, pour le journal d'installation
     */
    public function installIfMissing(): array
    {
        if (!$this->team->isEmpty()) {
            return [];
        }

        return $this->apply(deactivateOthers: false);
    }

    /**
     * Réapplique l'équipe de départ sur un site DÉJÀ installé.
     *
     * Écrit par identifiant plutôt que par suppression suivie d'une recréation :
     * les comptages et l'historique portent ces identifiants, et effacer une
     * fiche rendrait illisibles les saisies qui la citent. Une fiche est donc
     * mise à jour, jamais remplacée.
     *
     * Les personnes qui ne figurent pas dans la liste sont DÉSACTIVÉES et non
     * effacées, pour la même raison : leur historique doit rester lisible.
     *
     * @return list<string>
     */
    public function reapply(?string $adminPin = null, ?string $consultantPin = null): array
    {
        return $this->apply(deactivateOthers: true, adminPin: $adminPin, consultantPin: $consultantPin);
    }

    /** @return list<string> */
    private function apply(bool $deactivateOthers, ?string $adminPin = null, ?string $consultantPin = null): array
    {
        $journal = [];

        foreach (self::POSTES as $rang => [$id, $nom]) {
            // Le poste conserve son état actif s'il existe déjà : un site qui a
            // fermé son second poste ne doit pas le voir rouvrir tout seul.
            $existant = $this->team->workstation($id);
            $this->team->saveWorkstation(
                new Workstation($id, $existant?->name ?? $nom, $existant?->active ?? true),
                $rang + 1,
            );
        }

        $gardes = [];

        foreach ($this->fiches($adminPin, $consultantPin) as $rang => [$id, $prenom, $nom, $role, $poste, $langue, $pin]) {
            $gardes[] = $id;
            $nouveau = $this->team->consultant($id) === null;

            $this->team->saveConsultant(
                new Consultant(
                    $id,
                    $prenom,
                    $nom,
                    $role,
                    $poste,
                    true,
                    strtolower("$prenom.$nom") . '@merisu.example',
                    null,
                    ['Merisù Centrum'],
                    [$poste],
                    $langue,
                ),
                $this->hasher->hash($pin),
                $rang + 1,
            );

            $journal[] = \sprintf('%s %s %s', $nouveau ? '+' : '~', trim("$prenom $nom"), $nouveau ? 'créé' : 'mis à jour');
        }

        if ($deactivateOthers) {
            foreach ($this->team->consultants() as $autre) {
                if (\in_array($autre->id, $gardes, true) || !$autre->active) {
                    continue;
                }

                // Le code reste inchangé (null) : on ne cherche pas à le
                // neutraliser, la désactivation suffit à fermer la porte —
                // `byPinHash` ne rend que les comptes actifs.
                $this->team->saveConsultant(
                    new Consultant(
                        $autre->id,
                        $autre->firstName,
                        $autre->lastName,
                        $autre->role,
                        $autre->defaultWorkstationId,
                        false,
                        $autre->email,
                        null,
                        $autre->shops,
                        $autre->workstations,
                        $autre->locale,
                    ),
                    null,
                );
                $journal[] = \sprintf('· %s désactivé', $autre->displayName());
            }
        }

        return $journal;
    }

    /**
     * Les fiches, avec leurs codes.
     *
     * Les codes passés en argument l'emportent sur la configuration : sur un
     * serveur, `.env.local` impose ses propres valeurs, et c'était le seul
     * moyen de poser un code choisi sans aller éditer ce fichier à la main.
     *
     * @return list<array{0:string,1:string,2:string,3:Role,4:string,5:Locale,6:string}>
     */
    private function fiches(?string $adminPin, ?string $consultantPin): array
    {
        return [
            ['admin', 'Anna', 'Kowalska', Role::Admin, 'ws-1', Locale::Pl, $adminPin ?? $this->adminPin],
            ['consultant1', 'Gian', 'Marco', Role::Consultant, 'ws-1', Locale::It, $consultantPin ?? $this->consultant1Pin],
        ];
    }
}
