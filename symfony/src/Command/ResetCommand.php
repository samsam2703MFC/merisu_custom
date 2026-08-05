<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Efface les SAISIES pour repartir d'un poste vierge, sans toucher au réglage.
 *
 * Deux natures de données cohabitent en base, et les confondre coûterait cher :
 *
 * · la CONFIGURATION — produits, seuils, paramètres, points de check-list,
 *   note du jour — se règle en administration et représente des heures de
 *   travail. Elle n'est JAMAIS effacée ici ;
 * · les SAISIES — comptages, photos, plans figés, cochages, mouvements,
 *   historique — se refont en une journée. Ce sont elles qu'on remet à zéro
 *   pour rejouer un cycle complet.
 *
 * La confirmation est explicite et non interactive : cette commande peut être
 * lancée depuis un déploiement automatisé, où une invite resterait sans
 * réponse et où un « oui » par défaut serait une catastrophe silencieuse.
 */
#[AsCommand(
    name: 'merisu:reinitialiser',
    description: 'Efface les saisies (comptages, plans, check-list, historique) sans toucher à la configuration.',
)]
final class ResetCommand extends Command
{
    /**
     * Tables vidées, avec ce qu'elles contiennent.
     *
     * @var array<string,string>
     */
    private const SAISIES = [
        'inv_count' => 'comptages',
        'inv_count_photo' => 'photos de comptage',
        'inv_production_plan' => 'plans de production figés',
        'inv_material_movement' => 'mouvements de matière',
        'inv_checklist_entry' => 'cochages de check-list',
        'inv_audit' => 'historique',
    ];

    /** Tables épargnées. Listées pour que le journal le dise noir sur blanc. */
    private const CONFIGURATION = [
        'inv_product' => 'produits',
        'inv_par_matrix' => 'seuils',
        'inv_settings' => 'paramètres généraux',
        'inv_checklist_item' => 'points de check-list',
        'inv_day_note' => 'note du jour',
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'confirmer',
            null,
            InputOption::VALUE_REQUIRED,
            'Taper EFFACER pour confirmer. Sans cette valeur exacte, la commande ne fait que compter.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lignes = [];
        $total = 0;

        foreach (self::SAISIES as $table => $quoi) {
            $n = $this->compter($table);
            $total += $n;
            $lignes[] = [$quoi, $n];
        }

        $io->section('Saisies présentes');
        $io->table(['Données', 'Lignes'], $lignes);

        $io->section('Conservé dans tous les cas');
        $io->listing(array_map(
            fn (string $quoi, string $table): string => \sprintf('%s (%d)', $quoi, $this->compter($table)),
            self::CONFIGURATION,
            array_keys(self::CONFIGURATION),
        ));

        // Sans confirmation exacte : on a compté, on s'arrête. C'est le mode
        // par défaut, et c'est volontaire — une commande destructrice ne doit
        // jamais agir parce qu'on l'a lancée « pour voir ».
        if ($input->getOption('confirmer') !== 'EFFACER') {
            $io->warning(\sprintf(
                'Rien n\'a été effacé. Relancer avec --confirmer=EFFACER pour supprimer ces %d lignes.',
                $total,
            ));

            return Command::SUCCESS;
        }

        if ($total === 0) {
            $io->success('Aucune saisie à effacer : le poste est déjà vierge.');

            return Command::SUCCESS;
        }

        foreach (array_keys(self::SAISIES) as $table) {
            $this->connection->executeStatement('DELETE FROM ' . $table);
        }

        $io->success(\sprintf('%d lignes de saisie effacées. La configuration est intacte.', $total));

        return Command::SUCCESS;
    }

    private function compter(string $table): int
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
        } catch (\Throwable) {
            // Table absente d'une base plus ancienne : elle ne contient rien à
            // effacer, et son absence ne doit pas faire échouer le reste.
            return 0;
        }
    }
}
