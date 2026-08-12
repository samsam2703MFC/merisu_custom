<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Merisu\Inventory\Adapter\WeatherUnavailable;
use Merisu\Inventory\Service\ForecastService;
use Merisu\Inventory\Service\InventoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Va chercher la prévision de la semaine.
 *
 * ── Une fois par jour, pas plus
 *
 * L'offre « One Call by Call » compte mille appels offerts par jour, puis
 * facture. Une prévision à sept jours ne change pas d'heure en heure : la
 * rappeler toutes les cinq minutes n'apprendrait rien et coûterait trois cents
 * appels par jour. Une tâche planifiée quotidienne, tôt le matin, suffit —
 * avant que le premier comptage n'ouvre le plan de la journée.
 *
 *   0 5 * * *  php bin/console merisu:meteo
 *
 * ── Elle n'applique que si on le lui a demandé
 *
 * Écrire dans la semaine type change ce que la boutique produira. Sans le
 * réglage « appliquer automatiquement » coché en administration, la commande
 * met la prévision en base et s'arrête là : quelqu'un la regardera et
 * décidera. Le drapeau `--appliquer` force l'écriture pour un passage.
 */
#[AsCommand(
    name: 'merisu:meteo',
    description: 'Récupère la prévision de la semaine et la met en base.',
)]
final class WeatherCommand extends Command
{
    /**
     * L'auteur inscrit à l'historique quand c'est la machine qui agit.
     *
     * Une tâche planifiée qui applique la prévision CHANGE ce que la boutique
     * produira. Sans ligne d'historique, quelqu'un constate un lundi que le
     * plan a bougé et ne trouve personne à qui le demander. Le rôle
     * « SYSTEM » se distingue à l'écran des consultants et des
     * administrateurs, sans se faire passer pour l'un d'eux.
     */
    private const ACTOR_ID = 'merisu:meteo';

    private const ACTOR_ROLE = 'SYSTEM';

    public function __construct(
        private readonly ForecastService $forecast,
        private readonly InventoryService $inventory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('langue', null, InputOption::VALUE_REQUIRED, 'Langue des libellés renvoyés par le service.', 'fr')
            ->addOption('appliquer', null, InputOption::VALUE_NONE, "Écrit la prévision dans la semaine type, même si le réglage automatique est décoché.")
            ->addOption('etat', null, InputOption::VALUE_NONE, "Affiche la prévision en base, sans appeler le service.");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $aujourdhui = $this->inventory->today();

        if ($input->getOption('etat')) {
            return $this->show($io, $aujourdhui);
        }

        if (!$this->forecast->isConfigured()) {
            // Pas une panne : une fonction qu'on n'a pas réglée. Renvoyer un
            // échec ferait sonner la tâche planifiée chaque nuit sur une
            // installation qui n'a jamais demandé la météo.
            $io->warning('Aucune clé météo réglée : rien à faire. Voir Admin ▸ Météo.');

            return Command::SUCCESS;
        }

        try {
            $bilan = $this->forecast->refresh(
                $aujourdhui,
                (string) $input->getOption('langue'),
                self::ACTOR_ID,
                self::ACTOR_ROLE,
            );
        } catch (WeatherUnavailable $e) {
            $io->error(trim($e->getMessage() . ' — ' . $e->detail, ' —'));

            // Celle-ci EST une panne : la clé est réglée, et le service a
            // refusé. La tâche planifiée doit le signaler.
            return Command::FAILURE;
        }

        if ($input->getOption('appliquer') && !$bilan['autoApplied']) {
            $bilan['applied'] = $this->forecast->apply(
                $this->forecast->cached($aujourdhui),
                self::ACTOR_ID,
                self::ACTOR_ROLE,
            );
        }

        $io->success(sprintf(
            '%d jour(s) reçu(s), %d appliqué(s) à la semaine type.',
            $bilan['days'],
            $bilan['applied'],
        ));

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, string $today): int
    {
        $prevision = $this->forecast->cached($today);

        if ($prevision->isEmpty()) {
            $io->warning('Aucune prévision en base.');

            return Command::SUCCESS;
        }

        $lignes = [];

        foreach ($prevision->days as $jour) {
            $lignes[] = [
                $jour->date,
                $jour->dayOfWeek->value,
                $jour->kind->value,
                $jour->tempMin === null ? '—' : sprintf('%.0f°', $jour->tempMin),
                $jour->tempMax === null ? '—' : sprintf('%.0f°', $jour->tempMax),
                $jour->rainChance . ' %',
                $jour->summary,
            ];
        }

        $io->table(['Date', 'Jour', 'Temps', 'Min', 'Max', 'Pluie', 'Libellé'], $lignes);
        $io->writeln('Reçue le ' . ($this->forecast->fetchedAt() ?? '—'));

        return Command::SUCCESS;
    }
}
