<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Merisu\Inventory\Adapter\Consultant;
use Merisu\Inventory\Adapter\Workstation;
use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ChecklistSection;
use Merisu\Inventory\Domain\DayNote;
use Merisu\Inventory\Domain\DayOfWeek;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\Role;
use Merisu\Inventory\Domain\RoundingMode;
use Merisu\Inventory\Store\ConsultantStore;
use Merisu\Inventory\Store\SchemaInstaller;
use Merisu\Inventory\Store\Store;
use Merisu\Inventory\Service\PinHasher;
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

    /**
     * Points de check-list de départ, un par volet.
     *
     * Volontairement génériques et peu nombreux : ils montrent la mécanique et
     * se renomment en administration. Y coder les vrais contrôles d'hygiène
     * d'une cuisine imposerait un redéploiement à chaque ajustement — et ces
     * contrôles diffèrent d'un site à l'autre.
     *
     * @var array<string, list<string>>
     */
    private const PLACEHOLDER_CHECKLIST = [
        ChecklistSection::Opening->value => ['Point d\'ouverture 1', 'Point d\'ouverture 2'],
        ChecklistSection::Closing->value => ['Point de fermeture 1', 'Point de fermeture 2'],
        ChecklistSection::Quality->value => ['Contrôle qualité 1', 'Contrôle qualité 2'],
    ];

    /**
     * Consignes de marque de premier démarrage.
     *
     * Le Tiramishow et le Ciao / Grazie sont ceux de Merisù aujourd'hui ; ils
     * sont posés ici pour que l'écran ne soit pas vide à la première ouverture,
     * PAS pour être définitifs. Ils se réécrivent dans Admin ▸ Note du jour,
     * dans les quatre langues, sans redéploiement.
     *
     * @var list<array{id: string, heading: array<string,string>, body: array<string,string>}>
     */
    private const PLACEHOLDER_DAY_NOTES = [
        [
            'id' => 'note-tiramishow',
            'heading' => [
                'fr' => 'Le Tiramishow',
                'pl' => 'Tiramishow',
                'it' => 'Il Tiramishow',
                'es' => 'El Tiramishow',
            ],
            'body' => [
                'fr' => "Le tiramisu se monte DEVANT le client, jamais en coulisse. Le geste fait partie du produit : on annonce ce qu'on pose, couche après couche, et on prend le temps de la dernière pluie de cacao.\nLe détail du geste est sur le site de la marque.",
                'pl' => "Tiramisu składamy PRZY kliencie, nigdy na zapleczu. Ten gest jest częścią produktu: mówimy, co kładziemy, warstwa po warstwie, i nie spieszymy się z ostatnią posypką kakao.\nSzczegóły gestu znajdziesz na stronie marki.",
                'it' => "Il tiramisù si monta DAVANTI al cliente, mai dietro le quinte. Il gesto fa parte del prodotto: si annuncia ciò che si posa, strato dopo strato, e ci si prende il tempo per l'ultima pioggia di cacao.\nIl dettaglio del gesto è sul sito del marchio.",
                'es' => "El tiramisú se monta DELANTE del cliente, nunca entre bastidores. El gesto forma parte del producto: se anuncia lo que se pone, capa a capa, y se dedica tiempo a la última lluvia de cacao.\nEl detalle del gesto está en la web de la marca.",
            ],
        ],
        [
            'id' => 'note-ciao-grazie',
            'heading' => [
                'fr' => 'Ciao et Grazie',
                'pl' => 'Ciao i Grazie',
                'it' => 'Ciao e Grazie',
                'es' => 'Ciao y Grazie',
            ],
            'body' => [
                'fr' => "Ciao à chaque client qui entre, dès qu'il franchit la porte — même les mains prises.\nGrazie à chaque client qui repart, en le regardant.",
                'pl' => "Ciao do każdego klienta wchodzącego do sklepu, od progu — nawet z zajętymi rękami.\nGrazie do każdego wychodzącego klienta, patrząc mu w oczy.",
                'it' => "Ciao a ogni cliente che entra, appena varca la porta — anche con le mani occupate.\nGrazie a ogni cliente che esce, guardandolo negli occhi.",
                'es' => "Ciao a cada cliente que entra, en cuanto cruza la puerta — incluso con las manos ocupadas.\nGrazie a cada cliente que se va, mirándole a los ojos.",
            ],
        ],
    ];

    /** Matrice fictive : même seuil en semaine, renforcé le week-end. */
    private const WEEKDAY_PLACEHOLDER = 20;
    private const WEEKEND_PLACEHOLDER = 30;

    public function __construct(
        private readonly Store $store,
        private readonly SchemaInstaller $schema,
        private readonly ConsultantStore $consultants,
        private readonly PinHasher $hasher,
        private readonly string $adminPin,
        private readonly string $consultant1Pin,
        private readonly string $consultant2Pin,
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

        if ($this->store->checklistItems() !== []) {
            $io->writeln('· check-list déjà remplie, inchangée');
        } else {
            $points = 0;
            foreach (self::PLACEHOLDER_CHECKLIST as $section => $labels) {
                foreach ($labels as $rang => $label) {
                    $this->store->saveChecklistItem(new ChecklistItem(
                        strtolower($section) . '-' . ($rang + 1),
                        ChecklistSection::from($section),
                        ['fr' => $label, 'pl' => $label, 'it' => $label, 'es' => $label],
                        $rang + 1,
                        true,
                    ));
                    ++$points;
                }
            }
            $io->writeln("+ $points points de check-list créés");
        }

        if ($this->store->dayNotes() !== []) {
            $io->writeln('· note du jour déjà rédigée, inchangée');
        } else {
            foreach (self::PLACEHOLDER_DAY_NOTES as $rang => $note) {
                $this->store->saveDayNote(new DayNote(
                    $note['id'],
                    $note['heading'],
                    $note['body'],
                    $rang + 1,
                    true,
                ));
            }
            $io->writeln('+ ' . \count(self::PLACEHOLDER_DAY_NOTES) . ' consignes de note du jour créées');
        }

        $this->amorcerEquipe($io);

        $io->newLine();
        $io->warning('Données PLACEHOLDER : à remplacer via Admin ▸ Produits, Seuils et Check-list.');
        // La connexion se fait au seul code PIN à 6 chiffres.
        $io->writeln('Codes PIN de démonstration : admin 000000 · consultant1 111111 · consultant2 222222');
        $io->writeln('⚠️  À retirer avant toute ouverture aux utilisateurs (voir DEPLOIEMENT.md).');

        return Command::SUCCESS;
    }

    /**
     * Crée les postes et l'équipe de départ, une seule fois.
     *
     * Le compte administrateur est le seul indispensable : sans lui, personne
     * ne peut ouvrir l'administration pour créer les autres, et l'application
     * serait installée mais inaccessible.
     *
     * Les codes de départ sont ceux de la configuration — les mêmes que
     * l'implémentation de repli — et se changent ensuite dans Admin ▸ Équipe.
     * Ils ne sont écrits qu'en empreinte : la base ne contient aucun code
     * lisible, y compris ceux-ci.
     */
    private function amorcerEquipe(SymfonyStyle $io): void
    {
        if (!$this->consultants->isEmpty()) {
            $io->writeln('· équipe déjà créée, inchangée');

            return;
        }

        foreach ([['ws-1', 'Stanowisko 1'], ['ws-2', 'Stanowisko 2']] as $rang => [$id, $nom]) {
            $this->consultants->saveWorkstation(new Workstation($id, $nom, true), $rang + 1);
        }

        $fiches = [
            ['admin', 'Anna', 'Kowalska', Role::Admin, 'ws-1', Locale::Pl, $this->adminPin],
            ['consultant1', 'Marco', 'Bianchi', Role::Consultant, 'ws-1', Locale::It, $this->consultant1Pin],
            ['consultant2', 'Claire', 'Dubois', Role::Consultant, 'ws-2', Locale::Fr, $this->consultant2Pin],
        ];

        foreach ($fiches as $rang => [$id, $prenom, $nom, $role, $poste, $langue, $pin]) {
            $this->consultants->saveConsultant(
                new Consultant(
                    $id,
                    $prenom,
                    $nom,
                    $role,
                    $poste,
                    true,
                    strtolower("$prenom.$nom") . '@merisu.example',
                    null,
                    ['Merisù Centrum'],
                    [$poste],
                    $langue,
                ),
                $this->hasher->hash($pin),
                $rang + 1,
            );
        }

        $io->writeln('+ 2 postes et 3 comptes créés (Admin ▸ Équipe)');
    }
}
