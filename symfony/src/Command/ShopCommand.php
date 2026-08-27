<?php

declare(strict_types=1);

namespace Merisu\Inventory\Command;

use Merisu\Inventory\Domain\Shop;
use Merisu\Inventory\Store\ShopStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée la boutique de Poznań, une fois, depuis un déploiement.
 *
 * Une boutique se crée normalement dans Admin ▸ Boutiques : c'est le chemin
 * prévu, et il n'exige ni accès au serveur ni redéploiement. Cette commande
 * sert le seul cas où l'on veut la poser DEPUIS le déploiement — parce que le
 * réseau s'agrandit et qu'on préfère que l'ouverture parte du dépôt, traçable,
 * plutôt que d'une saisie à retrouver.
 *
 * ── Idempotente, et c'est tout l'intérêt
 *
 * Elle ne crée RIEN si une boutique de Poznań existe déjà : lancée deux fois,
 * elle ne fait pas deux Poznań, et lancée après qu'un administrateur a affiné
 * la fiche à l'écran, elle n'écrase pas son travail. Une commande de mise en
 * service qui doublerait la donnée à chaque appel serait un piège, pas un
 * outil.
 *
 * Elle ne RESSUSCITE pas non plus une boutique effacée à dessein : le contrôle
 * porte sur le nom, si bien qu'une fois Poznań renommée ou retirée, relancer
 * la commande la recrée — c'est assumé. Le geste est explicite (une option de
 * maintenance qu'on choisit), pas un pas silencieux de chaque déploiement.
 */
#[AsCommand(
    name: 'merisu:boutique-poznan',
    description: 'Crée la boutique de Poznań si elle n\'existe pas déjà.',
)]
final class ShopCommand extends Command
{
    public function __construct(private readonly ShopStore $shops)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->shops->all() as $boutique) {
            if (str_contains(self::flatten($boutique->name), 'poznan')) {
                $io->success(sprintf('Poznań existe déjà : « %s ». Rien à faire.', $boutique->name));

                return Command::SUCCESS;
            }
        }

        $slot = $this->shops->nextSlot();

        $this->shops->save(new Shop(
            id: $slot['id'],
            code: $slot['code'],
            name: 'Poznań Półwiejska',
            address: 'Półwiejska 18',
            postalCode: '61-885',
            city: 'Poznań',
            // Coordonnées de la rue, pour la météo : Poznań et Wrocław n'ont
            // pas le même ciel, et une prévision empruntée ferait produire l'un
            // selon le temps de l'autre.
            latitude: 52.4032,
            longitude: 16.9298,
            sortOrder: $slot['sortOrder'],
        ));

        // Le logo n'est pas posé ici : il s'accroche de lui-même. « Poznań »
        // dans le nom suffit à lui donner le badge livré dans `public/assets`,
        // et un administrateur reste libre d'en téléverser un autre.
        $io->success(sprintf('Boutique créée : « Poznań Półwiejska » (%s / %s).', $slot['id'], $slot['code']));

        return Command::SUCCESS;
    }

    /** « Poznań » → « poznan » : l'accent ne doit pas décider si la ville existe. */
    private static function flatten(string $name): string
    {
        return strtr(mb_strtolower(trim($name)), ['ł' => 'l', 'ó' => 'o', 'ń' => 'n', 'ą' => 'a', 'ę' => 'e', 'ś' => 's', 'ż' => 'z', 'ź' => 'z', 'ć' => 'c']);
    }
}
