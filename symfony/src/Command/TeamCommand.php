<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Merisu\Inventory\Service\TeamInstaller;
use Merisu\Inventory\Store\ConsultantStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Réapplique l'équipe de départ à un site DÉJÀ installé.
 *
 * L'installation ne crée l'équipe qu'une fois, quand la table est vide — c'est
 * ce qui empêche un déploiement d'écraser les vendeurs et les codes réglés
 * depuis. Cette commande est donc le seul moyen de remettre un site en service
 * sur l'équipe décrite en configuration.
 *
 * Elle n'efface rien : chaque fiche est écrite par identifiant, et les
 * personnes absentes de la liste sont désactivées. Les comptages et
 * l'historique portent ces identifiants, et une fiche effacée rendrait
 * illisibles les saisies qui la citent.
 */
#[AsCommand(
    name: 'merisu:equipe',
    description: "Affiche l'équipe, ou réapplique celle de la configuration.",
)]
final class TeamCommand extends Command
{
    public function __construct(
        private readonly ConsultantStore $team,
        private readonly TeamInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'reappliquer',
            null,
            InputOption::VALUE_NONE,
            "Réécrit l'équipe de la configuration et désactive les autres comptes.",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('reappliquer')) {
            foreach ($this->installer->reapply() as $ligne) {
                $io->writeln($ligne);
            }

            $io->writeln('Codes appliqués : ' . $this->installer->pinSummary());
            $io->newLine();
        }

        // L'état final, toujours : sans confirmation lisible, une commande
        // lancée à distance laisse à deviner ce qu'elle a produit.
        $io->section('Équipe');
        $io->table(
            ['Identifiant', 'Nom', 'Rôle', 'Poste', 'Actif'],
            array_map(
                static fn ($c): array => [
                    $c->id,
                    $c->displayName(),
                    $c->role->value,
                    $c->defaultWorkstationId ?? '—',
                    $c->active ? 'oui' : 'non',
                ],
                $this->team->consultants(),
            ),
        );

        return Command::SUCCESS;
    }
}
