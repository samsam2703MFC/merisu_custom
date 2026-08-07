<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Merisu\Inventory\Service\SyncService;
use Merisu\Inventory\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vide la file d'envoi vers le système hôte.
 *
 * À déclencher par une tâche planifiée, toutes les cinq minutes environ. La
 * commande est sans effet si la file est vide, et ne fait rien du tout tant
 * qu'aucune implémentation réelle de `InventorySyncInterface` n'est branchée —
 * les comptages s'accumulent alors intacts, sans consommer de tentative.
 */
#[AsCommand(
    name: 'merisu:synchroniser',
    description: "Envoie au système hôte les comptages validés en attente.",
)]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly Store $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Nombre maximal d\'envois par passage.', '50')
            ->addOption('etat', null, InputOption::VALUE_NONE, 'Affiche seulement l\'état de la file, sans rien envoyer.')
            ->addOption('reprendre', null, InputOption::VALUE_NONE, 'Remet en file les envois abandonnés, puis vide la file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $etat = $this->store->syncCounts();
        $io->definitionList(
            ['En attente' => $etat['PENDING']],
            ['Envoyés' => $etat['SENT']],
            ['Abandonnés' => $etat['FAILED']],
        );

        if ($input->getOption('etat')) {
            return Command::SUCCESS;
        }

        if ($input->getOption('reprendre')) {
            $repris = $this->store->retrySyncFailed();
            $io->writeln(sprintf('%d envoi(s) remis en file.', $repris));
        }

        $bilan = $this->sync->drain(max(1, (int) $input->getOption('limite')));

        if ($bilan['skipped'] > 0) {
            $io->warning(sprintf(
                "Aucune intégration branchée : %d envoi(s) restent en attente, intacts.\n"
                . "Voir InventorySyncInterface — il manque le contrat des endpoints d'inventaire TF Buddy.",
                $bilan['skipped'],
            ));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d envoyé(s), %d à réessayer, %d abandonné(s).',
            $bilan['sent'],
            $bilan['retried'],
            $bilan['failed'],
        ));

        // Un abandon n'est pas une panne de la commande : la ligne est en base,
        // avec son erreur, et l'administrateur la reprendra. Renvoyer un échec
        // ferait sonner la tâche planifiée à chaque passage.
        return Command::SUCCESS;
    }
}
