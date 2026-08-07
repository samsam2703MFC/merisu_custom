<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Ce qu'il faut réunir pour signer un point de check-list.
 *
 * Isolé du contrôleur pour que les règles soient vérifiables sans base, sans
 * session et sans requête HTTP — ce sont elles qui donnent sa valeur à la
 * signature, et une règle qu'on ne peut pas tester est une règle qu'on croit
 * appliquée.
 *
 * Trois règles, et une seule raison derrière les trois : ce qui est signé doit
 * rester lisible des mois plus tard, par quelqu'un qui n'était pas là.
 */
final readonly class ChecklistSignature
{
    private function __construct(
        /** @var list<string> Codes d'erreur, vide si la signature est recevable. */
        public array $issues,
    ) {
    }

    public function isValid(): bool
    {
        return $this->issues === [];
    }

    /**
     * Vérifie une signature avant de l'enregistrer.
     *
     * @param bool $pinRecognised Le code a-t-il désigné quelqu'un ?
     * @param bool $photoProvided Une photo accompagne-t-elle la signature ?
     */
    public static function check(
        ChecklistItem $item,
        ChecklistStatus $status,
        bool $pinRecognised,
        ?string $note,
        bool $photoProvided,
    ): self {
        $issues = [];

        // Sans code reconnu, la signature ne désigne personne — et une
        // check-list qui ne dit pas qui a fait quoi ne sert à rien.
        if (!$pinRecognised) {
            $issues[] = 'signature.unknownPin';
        }

        // ATTENTE n'est pas un choix : c'est l'absence de choix. Il ne peut
        // donc pas être signé, sans quoi « en attente, signé par Marco à 8 h »
        // apparaîtrait dans l'historique et ne voudrait rien dire.
        if (!$status->isSettled()) {
            $issues[] = 'signature.statusRequired';
        }

        // Un échec sans motif ne sert à personne le lendemain matin : il dit
        // qu'il y a un problème sans dire lequel, et oblige à retrouver la
        // personne pour le lui demander.
        if ($status->needsReason() && trim((string) $note) === '') {
            $issues[] = 'signature.reasonRequired';
        }

        // La photo n'est exigée que pour un point RÉELLEMENT fait. L'exiger
        // aussi pour un point passé ou échoué serait absurde : on ne
        // photographie pas ce qu'on n'a pas fait.
        if ($item->requiresPhoto && $status === ChecklistStatus::Done && !$photoProvided) {
            $issues[] = 'signature.photoRequired';
        }

        return new self($issues);
    }
}
