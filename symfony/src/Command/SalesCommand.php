<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Merisu\Inventory\Adapter\PosServiceInterface;
use Merisu\Inventory\Adapter\PosUnavailable;
use Merisu\Inventory\Domain\BusinessDate;
use Merisu\Inventory\Domain\SalesBreakdown;
use Merisu\Inventory\Domain\SalesPeriod;
use Merisu\Inventory\Service\InventoryService;
use Merisu\Inventory\Service\ShopPos;
use Merisu\Inventory\Store\ShopStore;
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

    /** Largeur d'une fenêtre de rattrapage, en jours. */
    private const WINDOW_DAYS = 90;

    /**
     * Fenêtres vides d'affilée avant de conclure qu'on a atteint le début.
     *
     * Deux, et non une : une boutique peut avoir fermé un trimestre entier —
     * travaux, saison — et s'arrêter au premier trou aurait laissé tout ce qui
     * précède hors de portée.
     */
    private const EMPTY_WINDOWS = 2;

    /** Garde-fou : vingt fenêtres de quatre-vingt-dix jours font cinq ans. */
    private const MAX_WINDOWS = 20;

    public function __construct(
        private readonly PosServiceInterface $pos,
        private readonly Store $store,
        private readonly InventoryService $inventory,
        private readonly ShopStore $shops,
        private readonly ShopPos $shopPos,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('jours', null, InputOption::VALUE_REQUIRED, 'Nombre de jours à relever.', (string) self::DEFAULT_DAYS)
            ->addOption('depuis', null, InputOption::VALUE_REQUIRED, 'Date de début (AAAA-MM-JJ), à la place de --jours.')
            ->addOption('tout', null, InputOption::VALUE_NONE, "Remonte TOUT l'historique que la caisse veut bien rendre.")
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
                $ecrites = $input->getOption('tout')
                    ? $this->toutRemonter($io, $to)
                    : $this->releverToutesBoutiques($io, $from, $to);
            } catch (PosUnavailable $e) {
                $io->error(trim($e->getMessage() . ' — ' . $e->detail, ' —'));

                return Command::FAILURE;
            }

            if (!$input->getOption('tout')) {
                $io->writeln(sprintf('%s → %s : %d ligne(s) relevée(s).', $from, $to, $ecrites));
            }

            // L'analyse qui suit doit porter sur ce qu'on vient de relever, et
            // non sur les six semaines par défaut.
            if ($input->getOption('tout')) {
                $etendue = $this->store->salesRange();
                $from = $etendue['from'] ?? $from;
            }
        }

        return $this->montrer($io, $from, $to, (string) $input->getOption('par'));
    }

    /**
     * Relève un intervalle, boutique par boutique.
     *
     * ── Une caisse par boutique, un seau par boutique
     *
     * Trois boutiques versaient dans le même total : le réseau n'avait qu'un
     * chiffre global, et l'on ne pouvait pas dire laquelle tenait son
     * objectif. Chaque relevé porte désormais le code de sa boutique.
     *
     * Sans aucune boutique enregistrée — une installation d'une seule caisse —
     * on relève comme avant, sous un code vide. Rien à migrer le jour où la
     * deuxième ouvre : les anciennes lignes gardent leur code vide, les
     * nouvelles portent le leur.
     */
    private function releverToutesBoutiques(SymfonyStyle $io, string $from, string $to): int
    {
        $boutiques = $this->shops->all(activeOnly: true);

        if ($boutiques === []) {
            return $this->store->saveSales($this->pos->sales($from, $to));
        }

        $total = 0;

        foreach ($boutiques as $boutique) {
            $caisse = $this->shopPos->forShop($boutique);

            if ($caisse === null) {
                // Pas une panne : une boutique dont la caisse n'est pas encore
                // réglée. Le dire, et passer à la suivante — s'arrêter aurait
                // privé les autres de leur relevé.
                $io->writeln(sprintf('  %-24s caisse non réglée', $boutique->name));

                continue;
            }

            $lignes = $this->store->saveSales($caisse->sales($from, $to, $boutique->code));
            $total += $lignes;

            $io->writeln(sprintf('  %-24s %d ligne(s)', $boutique->name, $lignes));
        }

        return $total;
    }

    /**
     * Remonte tout l'historique, par fenêtres.
     *
     * ── Pourquoi par fenêtres, et non d'un seul appel
     *
     * Un intervalle de douze mois passe en un appel sur une boutique ouverte
     * depuis quatre mois — mais rend deux mégaoctets et demi. Sur une boutique
     * qui tourne depuis trois ans, le même appel demanderait à la caisse
     * d'agréger un million de lignes et de les rendre d'un bloc : c'est le
     * genre de requête qui expire à mi-chemin, et qui ne laisse rien.
     *
     * Par tranches de trois mois, chaque appel est court, et ce qui est déjà
     * remonté est déjà en base.
     *
     * ── Quand s'arrêter
     *
     * La caisse ne dit pas depuis quand elle a des données : elle rend zéro,
     * simplement. Deux fenêtres vides d'affilée valent donc « on a atteint le
     * début » — une seule ne suffirait pas, une boutique peut avoir fermé un
     * trimestre entier.
     */
    private function toutRemonter(SymfonyStyle $io, string $to): int
    {
        $total = 0;
        $vides = 0;
        $fin = $to;

        for ($fenetre = 0; $fenetre < self::MAX_WINDOWS; ++$fenetre) {
            $debut = BusinessDate::addDays($fin, -self::WINDOW_DAYS + 1);
            $io->writeln(sprintf('  %s → %s', $debut, $fin));
            $lignes = $this->releverToutesBoutiques($io, $debut, $fin);

            $total += $lignes;
            $vides = $lignes === 0 ? $vides + 1 : 0;

            if ($vides >= self::EMPTY_WINDOWS) {
                break;
            }

            $fin = BusinessDate::addDays($debut, -1);
        }

        $io->writeln(sprintf('%d ligne(s) relevée(s) au total.', $total));

        return $total;
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
