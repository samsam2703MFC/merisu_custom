<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Merisu\Inventory\Adapter\PosServiceInterface;
use Merisu\Inventory\Adapter\PosUnavailable;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\SalesBreakdown;
use Merisu\Inventory\Domain\SalesPeriod;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Relève les ventes de la caisse et les garde.
 *
 * ── Une fois par nuit suffit
 *
 * Le rapport de la caisse met plusieurs secondes pour six semaines, et les
 * ventes d'hier ne changent plus. Une tâche quotidienne, après la fermeture :
 *
 *   30 2 * * *  php bin/console merisu:ventes
 *
 * Elle redemande les six dernières semaines à chaque passage, et non le seul
 * jour écoulé : une commande rouverte ou un remboursement passé après coup
 * modifie une journée déjà relevée, et un relevé qui ne regarderait qu'hier
 * garderait l'erreur pour toujours. Réécrire six semaines coûte un appel.
 */
#[AsCommand(
    name: 'merisu:ventes',
    description: 'Relève les ventes de la caisse, produit par produit et jour par jour.',
)]
final class SalesCommand extends Command
{
    /**
     * Six semaines, comme la moyenne du stock minimum.
     *
     * C'est elle que ce relevé alimente : demander moins l'aurait privée de
     * son historique, demander plus aurait allongé chaque nuit pour des
     * chiffres que plus personne ne regarde.
     */
    private const DEFAULT_DAYS = 42;

    public function __construct(
        private readonly PosServiceInterface $pos,
        private readonly Store $store,
        private readonly InventoryService $inventory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('jours', null, InputOption::VALUE_REQUIRED, 'Nombre de jours à relever.', (string) self::DEFAULT_DAYS)
            ->addOption('depuis', null, InputOption::VALUE_REQUIRED, 'Date de début (AAAA-MM-JJ), à la place de --jours.')
            ->addOption('etat', null, InputOption::VALUE_NONE, "Affiche ce qui est en base, sans appeler la caisse.")
            ->addOption('par', null, InputOption::VALUE_REQUIRED, 'Analyse : jour, semaine, mois, jour-de-semaine.', 'jour');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $to = $this->inventory->today();
        $depuis = (string) ($input->getOption('depuis') ?? '');
        $from = BusinessDate::isValid($depuis)
            ? $depuis
            : BusinessDate::addDays($to, -max(1, (int) $input->getOption('jours')) + 1);

        if (!$input->getOption('etat')) {
            if (!$this->pos->isConfigured()) {
                $io->warning('Aucun identifiant de caisse. Voir Admin ▸ Caisse.');

                return Command::SUCCESS;
            }

            try {
                $ventes = $this->pos->sales($from, $to);
            } catch (PosUnavailable $e) {
                $io->error(trim($e->getMessage() . ' — ' . $e->detail, ' —'));

                return Command::FAILURE;
            }

            $ecrites = $this->store->saveSales($ventes);
            $io->writeln(sprintf('%s → %s : %d ligne(s) relevée(s).', $from, $to, $ecrites));
        }

        return $this->montrer($io, $from, $to, (string) $input->getOption('par'));
    }

    private function montrer(SymfonyStyle $io, string $from, string $to, string $par): int
    {
        $ventes = $this->store->sales($from, $to);

        if ($ventes === []) {
            $io->warning('Aucune vente en base sur cet intervalle.');

            return Command::SUCCESS;
        }

        $periode = match (mb_strtolower(trim($par))) {
            'semaine' => SalesPeriod::Week,
            'mois' => SalesPeriod::Month,
            'jour-de-semaine', 'jds' => SalesPeriod::Weekday,
            default => SalesPeriod::Day,
        };

        $lignes = [];
        foreach (SalesBreakdown::of($ventes, $periode) as $case) {
            $lignes[] = [
                $case->key,
                sprintf('%.0f', $case->quantity),
                $case->days,
                sprintf('%.1f', $case->averagePerDay()),
                sprintf('%.2f', $case->revenue),
            ];
        }

        $io->table(['Période', 'Quantité', 'Jours', 'Moy./jour', 'Recette'], $lignes);

        $io->writeln('Meilleures ventes :');
        $classement = [];
        foreach (array_slice(SalesBreakdown::byProduct($ventes), 0, 10) as $p) {
            $classement[] = [$p['name'], sprintf('%.0f', $p['quantity']), $p['days'], sprintf('%.2f', $p['revenue'])];
        }
        $io->table(['Produit', 'Quantité', 'Jours', 'Recette'], $classement);

        return Command::SUCCESS;
    }
}
