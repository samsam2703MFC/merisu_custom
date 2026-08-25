<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Doctrine\DBAL\Connection;
use Merisu\Inventory\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vide les compositions — toutes les lignes, d'un coup.
 *
 * L'écran sait déjà vider une fiche à la fois. Le faire quarante-neuf fois à la
 * main n'est pas une option, et un administrateur pressé finirait par en
 * oublier ou par en supprimer une de trop.
 *
 * ── Ce qui part, et ce qui reste
 *
 * Seules les LIGNES partent : « ce produit consomme tant de crème ». Les
 * fiches, elles, restent toutes — produits en vente comme recettes. Un
 * tiramisu se vend toujours après cette commande ; il ne se fabrique
 * simplement plus à partir de rien de décrit, et l'écran de composition
 * l'attend, vide.
 *
 * Supprimer les fiches serait un autre geste, autrement plus lourd : elles
 * portent des prix, des seuils, un historique de comptage.
 *
 * ── Pourquoi elle compte avant d'effacer
 *
 * Par défaut, elle NE FAIT QUE COMPTER. Il faut `--confirmer=VIDER`, à la
 * lettre, pour qu'elle efface. Une commande destructrice ne doit jamais agir
 * parce qu'on l'a lancée « pour voir » — et celle-ci se lance depuis un
 * déploiement, où aucune invite n'aurait de réponse.
 *
 * Le delta technique n'a plus de nomenclature après ce passage : il cessera de
 * signaler des écarts jusqu'à ce que les compositions soient ressaisies. C'est
 * dit à l'écran plutôt que découvert la semaine suivante.
 */
#[AsCommand(
    name: 'merisu:compositions',
    description: 'Compte les lignes de composition, et les efface toutes sur confirmation.',
)]
final class CompositionsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Store $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'confirmer',
            null,
            InputOption::VALUE_REQUIRED,
            'Taper VIDER pour confirmer. Sans cette valeur exacte, la commande ne fait que compter.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lignes = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inv_recipe_line');
        $fiches = (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT product_id) FROM inv_recipe_line');

        $io->section('Compositions en base');
        $io->table(
            ['Données', 'Nombre'],
            [
                ['lignes de composition', $lignes],
                ['fiches qui en portent', $fiches],
            ],
        );

        // Nommées, et pas seulement comptées : c'est la dernière occasion de
        // voir qu'on s'apprête à vider une fiche à laquelle on tenait.
        $noms = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT p.code FROM inv_recipe_line r'
            . ' JOIN inv_product p ON p.id = r.product_id ORDER BY p.code',
        );

        if ($noms !== []) {
            $io->section('Fiches concernées');
            $io->listing(array_map(strval(...), $noms));
        }

        $io->note('Les FICHES restent toutes — produits en vente comme recettes. Seules les lignes partent.');

        if ($input->getOption('confirmer') !== 'VIDER') {
            $io->warning(\sprintf(
                'Rien n\'a été effacé. Relancer avec --confirmer=VIDER pour supprimer ces %d ligne(s).',
                $lignes,
            ));

            return Command::SUCCESS;
        }

        if ($lignes === 0) {
            $io->success('Aucune composition à vider : la base est déjà nette.');

            return Command::SUCCESS;
        }

        $this->connection->executeStatement('DELETE FROM inv_recipe_line');

        // Tracé en audit : une suppression de masse doit laisser un nom, une
        // heure et un nombre, sinon personne ne saura d'où vient le vide.
        $this->store->audit('console', 'ADMIN', 'RECIPES_CLEARED', null, null, [
            'lines' => $lignes,
            'products' => $fiches,
        ]);

        $io->success(\sprintf('%d ligne(s) de composition effacée(s) sur %d fiche(s).', $lignes, $fiches));
        $io->warning(
            'Le delta technique n\'a plus de nomenclature : il ne signalera plus d\'écart'
            . ' tant que les compositions ne seront pas ressaisies.',
        );

        return Command::SUCCESS;
    }
}
