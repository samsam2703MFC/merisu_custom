<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Merisu\Inventory\Domain\GeneralSettings;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lecture et écriture d'un paramètre général, en ligne de commande.
 *
 * Tout se règle normalement dans Admin ▸ Paramètres : c'est le chemin prévu,
 * et il n'exige ni accès au serveur ni redéploiement (§2). Cette commande sert
 * les cas où l'écran n'est pas atteignable — première mise en service, code
 * administrateur perdu, réglage à poser depuis un déploiement automatisé.
 *
 * La liste des noms acceptés est CLOSE. Une commande qui écrirait n'importe
 * quelle colonne serait une porte d'entrée sur la base ; celle-ci ne peut
 * toucher qu'aux réglages qu'un administrateur pourrait déjà changer à l'écran.
 */
#[AsCommand(
    name: 'merisu:parametre',
    description: 'Lit ou modifie un paramètre général (Admin ▸ Paramètres en ligne de commande).',
)]
final class SettingCommand extends Command
{
    /** Noms acceptés, et rien d'autre. */
    private const NOMS = [
        'openingTime',
        'closingTime',
        'timezone',
        'defaultLocale',
        'deltaTolerance',
        'monthlyTiramisuTarget',
    ];

    public function __construct(private readonly Store $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('nom', InputArgument::OPTIONAL, 'Paramètre : ' . implode(', ', self::NOMS))
            ->addArgument('valeur', InputArgument::OPTIONAL, 'Nouvelle valeur. Absente : la commande se contente de lire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $courant = $this->store->settings();

        $nom = $input->getArgument('nom');

        // Sans argument : l'inventaire des réglages. C'est ce qu'on veut voir
        // en premier quand on se demande pourquoi un écran est vide.
        if ($nom === null) {
            $io->table(['Paramètre', 'Valeur'], array_map(
                fn (string $n): array => [$n, $this->lire($courant, $n)],
                self::NOMS,
            ));

            return Command::SUCCESS;
        }

        if (!\in_array($nom, self::NOMS, true)) {
            $io->error(\sprintf('Paramètre inconnu : « %s ». Connus : %s.', $nom, implode(', ', self::NOMS)));

            return Command::INVALID;
        }

        $valeur = $input->getArgument('valeur');

        if ($valeur === null) {
            $io->writeln(\sprintf('%s = %s', $nom, $this->lire($courant, $nom)));

            return Command::SUCCESS;
        }

        try {
            $nouveau = $this->appliquer($courant, $nom, (string) $valeur);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $this->store->saveSettings($nouveau);

        $io->success(\sprintf(
            '%s : %s → %s',
            $nom,
            $this->lire($courant, $nom),
            $this->lire($nouveau, $nom),
        ));

        return Command::SUCCESS;
    }

    private function lire(GeneralSettings $s, string $nom): string
    {
        return match ($nom) {
            'openingTime' => $s->openingTime,
            'closingTime' => $s->closingTime,
            'timezone' => $s->timezone,
            'defaultLocale' => $s->defaultLocale->value,
            'deltaTolerance' => (string) $s->deltaTolerance,
            'monthlyTiramisuTarget' => (string) $s->monthlyTiramisuTarget,
            default => '',
        };
    }

    /**
     * Chaque valeur est validée avant d'entrer en base.
     *
     * Un fuseau inconnu ou une heure mal formée fausserait silencieusement
     * toutes les dates métier : mieux vaut refuser ici que le découvrir au
     * poste, un matin, sur un comptage rattaché au mauvais jour.
     */
    private function appliquer(GeneralSettings $s, string $nom, string $valeur): GeneralSettings
    {
        $valeur = trim($valeur);

        return match ($nom) {
            'openingTime', 'closingTime' => $this->avecHeure($s, $nom, $valeur),
            'timezone' => \in_array($valeur, \DateTimeZone::listIdentifiers(), true)
                ? new GeneralSettings($s->openingTime, $s->closingTime, $valeur, $s->defaultLocale, $s->photoRequired, $s->photoPerProduct, $s->deltaTolerance, $s->monthlyTiramisuTarget)
                : throw new \InvalidArgumentException('Fuseau horaire inconnu : ' . $valeur),
            'defaultLocale' => new GeneralSettings(
                $s->openingTime,
                $s->closingTime,
                $s->timezone,
                Locale::tryFromLoose($valeur) ?? throw new \InvalidArgumentException('Langue non prise en charge : ' . $valeur),
                $s->photoRequired,
                $s->photoPerProduct,
                $s->deltaTolerance,
                $s->monthlyTiramisuTarget,
            ),
            'deltaTolerance' => is_numeric($n = str_replace(',', '.', $valeur)) && (float) $n >= 0
                ? new GeneralSettings($s->openingTime, $s->closingTime, $s->timezone, $s->defaultLocale, $s->photoRequired, $s->photoPerProduct, (float) $n, $s->monthlyTiramisuTarget)
                : throw new \InvalidArgumentException('Tolérance attendue : un nombre positif, ex. 0.05.'),
            'monthlyTiramisuTarget' => ctype_digit($valeur)
                ? new GeneralSettings($s->openingTime, $s->closingTime, $s->timezone, $s->defaultLocale, $s->photoRequired, $s->photoPerProduct, $s->deltaTolerance, (int) $valeur)
                : throw new \InvalidArgumentException('Objectif attendu : un entier positif, ex. 4000. 0 retire la jauge.'),
            default => throw new \InvalidArgumentException('Paramètre inconnu : ' . $nom),
        };
    }

    private function avecHeure(GeneralSettings $s, string $nom, string $valeur): GeneralSettings
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valeur) !== 1) {
            throw new \InvalidArgumentException('Heure attendue au format HH:MM, ex. 08:00.');
        }

        return new GeneralSettings(
            $nom === 'openingTime' ? $valeur : $s->openingTime,
            $nom === 'closingTime' ? $valeur : $s->closingTime,
            $s->timezone,
            $s->defaultLocale,
            $s->photoRequired,
            $s->photoPerProduct,
            $s->deltaTolerance,
            $s->monthlyTiramisuTarget,
        );
    }
}
