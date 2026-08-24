<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Merisu\Inventory\Adapter\PosServiceInterface;
use Merisu\Inventory\Adapter\PosUnavailable;
use Merisu\Inventory\Service\PosImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le catalogue de la caisse, depuis le terminal.
 *
 * ── Pourquoi une commande, alors que l'écran le fait déjà
 *
 * Un import de quarante articles se fait aussi bien par SSH, sur un serveur
 * où personne n'ouvrira de navigateur : après une réinstallation, ou quand la
 * boutique vient d'ajouter vingt références. La règle est la même —
 * `PosImportService`, partagé avec Admin ▸ Caisse — pour que les deux
 * chemins rangent pareil.
 *
 * ── Elle MONTRE avant d'écrire
 *
 * Sans option, elle interroge la caisse et affiche ce qui entrerait, sans
 * rien toucher. Un import qui part au premier appel sur une boutique de trois
 * cents articles est irrattrapable.
 */
#[AsCommand(
    name: 'merisu:caisse',
    description: 'Montre le catalogue de la caisse, et le reprend sur demande.',
)]
final class PosCommand extends Command
{
    /**
     * L'auteur inscrit à l'historique quand l'import vient du terminal.
     *
     * Un import CHANGE le catalogue de la boutique. Sans ligne d'historique,
     * quelqu'un constate vingt produits nouveaux un lundi matin et ne trouve
     * personne à qui le demander.
     */
    private const ACTOR_ID = 'merisu:caisse';

    private const ACTOR_ROLE = 'SYSTEM';

    public function __construct(
        private readonly PosServiceInterface $pos,
        private readonly PosImportService $import,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('categories', null, InputOption::VALUE_NONE, 'Reprend les catégories absentes.')
            ->addOption('articles', null, InputOption::VALUE_NONE, 'Reprend les articles absents et rattache les autres.')
            ->addOption('tout', null, InputOption::VALUE_NONE, 'Les catégories puis les articles.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->pos->isConfigured()) {
            $io->warning("Aucun identifiant de caisse. Voir Admin ▸ Caisse, ou GOPOS_CLIENT_ID / GOPOS_CLIENT_SECRET / GOPOS_ORGANIZATION_ID.");

            return Command::SUCCESS;
        }

        $tout = (bool) $input->getOption('tout');
        $faireCategories = $tout || (bool) $input->getOption('categories');
        $faireArticles = $tout || (bool) $input->getOption('articles');

        try {
            // QUELLE boutique répond. « Connexion réussie » n'aurait pas dit
            // laquelle, et l'on peut très bien avoir branché l'organisation du
            // voisin.
            $io->definitionList(['Organisation' => $this->pos->ping()]);

            if (!$faireCategories && !$faireArticles) {
                return $this->montrer($io);
            }

            if ($faireCategories) {
                $bilan = $this->import->importCategories(self::ACTOR_ID, self::ACTOR_ROLE);
                $io->writeln(sprintf('Catégories : %d vue(s), %d ajoutée(s).', $bilan['seen'], $bilan['added']));
            }

            if ($faireArticles) {
                $bilan = $this->import->importItems(self::ACTOR_ID, self::ACTOR_ROLE);
                $io->writeln(sprintf(
                    'Articles : %d vu(s), %d créé(s), %d rattaché(s).',
                    $bilan['seen'],
                    $bilan['created'],
                    $bilan['linked'],
                ));
            }
        } catch (PosUnavailable $e) {
            $io->error(trim($e->getMessage() . ' — ' . $e->detail, ' —'));

            return Command::FAILURE;
        }

        $io->success('Catalogue repris.');

        return Command::SUCCESS;
    }

    /** Ce que la caisse connaît, sans rien écrire. */
    private function montrer(SymfonyStyle $io): int
    {
        $categories = $this->pos->categories();
        $articles = $this->pos->items();

        $io->writeln(sprintf('%d catégorie(s) : %s', count($categories), implode(', ', array_map(
            static fn ($c): string => $c->name,
            $categories,
        ))));

        $lignes = [];
        foreach ($articles as $a) {
            $lignes[] = [$a->externalId, $a->name, $a->categoryName ?? '—', $a->enabled ? 'actif' : 'inactif'];
        }

        $io->table(['Réf.', 'Article', 'Catégorie', 'État'], $lignes);
        $io->note("Rien n'a été écrit. --categories, --articles ou --tout pour reprendre.");

        return Command::SUCCESS;
    }
}
