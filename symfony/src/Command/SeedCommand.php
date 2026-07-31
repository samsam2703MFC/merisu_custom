<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\RoundingMode;
use Merisu\Inventory\Store\SchemaInstaller;
use Merisu\Inventory\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installe le schéma et crée les 8 emplacements produits ainsi qu'une matrice
 * de seuils FICTIVE, pour rendre l'application démontrable immédiatement.
 *
 * ⚠️ Toutes ces données sont des PLACEHOLDERS. Aucune valeur produit n'est codée
 * dans l'application : elles se remplacent depuis Admin ▸ Produits et
 * Admin ▸ Seuils, sans redéploiement.
 *
 * Idempotent : rejouable sans créer de doublon ni écraser une saisie réelle.
 */
#[AsCommand(name: 'merisu:seed', description: 'Installe le schéma et les données de démonstration')]
final class SeedCommand extends Command
{
    /** Libellés provisoires cités dans le cahier des charges — à écraser en admin. */
    private const PLACEHOLDER_LABELS = [
        'Cream 1', 'Cream 2', 'Cream 3', 'Coffee',
        'Biscuit', 'Coffee 2', 'Slot 7', 'Slot 8',
    ];

    /** Matrice fictive : même seuil en semaine, renforcé le week-end. */
    private const WEEKDAY_PLACEHOLDER = 20;
    private const WEEKEND_PLACEHOLDER = 30;

    public function __construct(
        private readonly Store $store,
        private readonly SchemaInstaller $schema,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->schema->install();
        $io->success('Schéma appliqué.');

        $existing = [];
        foreach ($this->store->products() as $product) {
            $existing[$product->code] = true;
        }

        $created = 0;
        foreach (self::PLACEHOLDER_LABELS as $index => $label) {
            $code = 'PRODUIT_' . ($index + 1);

            if (isset($existing[$code])) {
                $io->writeln("· $code déjà présent, conservé tel quel");
                continue;
            }

            // Le même libellé provisoire dans les 4 langues : c'est un marqueur
            // visible de « donnée à remplir », pas une traduction.
            $this->store->saveProduct(new Product(
                'product-' . ($index + 1),
                $code,
                ['fr' => $label, 'pl' => $label, 'it' => $label, 'es' => $label],
                'pcs',
                true,
                0.0,
                1.0,
                RoundingMode::Ceil,
                null,
                $index + 1,
            ));
            $io->writeln("+ $code créé");
            ++$created;
        }

        if ($this->store->parMatrix() !== []) {
            $io->writeln('· matrice des seuils déjà remplie, inchangée');
        } else {
            $cells = 0;
            foreach ($this->store->products() as $product) {
                foreach (DayOfWeek::all() as $day) {
                    $required = \in_array($day, [DayOfWeek::Sat, DayOfWeek::Sun], true)
                        ? self::WEEKEND_PLACEHOLDER
                        : self::WEEKDAY_PLACEHOLDER;

                    $this->store->saveParEntry($product->id, $day, (float) $required);
                    ++$cells;
                }
            }
            $io->writeln("+ $cells seuils fictifs créés");
        }

        $io->newLine();
        $io->warning('Données PLACEHOLDER : à remplacer via Admin ▸ Produits et Admin ▸ Seuils.');
        $io->writeln('Comptes de démonstration : admin/0000, consultant1/1111, consultant2/2222');

        return Command::SUCCESS;
    }
}
