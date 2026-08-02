<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * Implémentation de REPLI — données de DÉMONSTRATION.
 *
 * ⚠️ Ces chiffres sont inventés. Ils servent à rendre l'écran Réseau visible
 * avant que la caisse ne soit branchée, et à rien d'autre : aucune décision ne
 * doit s'appuyer dessus.
 *
 * Les noms de boutiques sont volontairement génériques et les montants ronds,
 * pour qu'on ne les confonde pas une seconde avec de vraies mesures. Voir
 * `ShopRankingServiceInterface` pour brancher la source réelle.
 */
final class LocalShopRankingService implements ShopRankingServiceInterface
{
    /** @var list<array{0:string,1:string,2:string,3:float,4:int,5:int,6:string}> */
    private const DEMO = [
        // id,            nom,                  pays, CA,       clients, tiramisu, devise
        ['pl-centrum',   'Merisù Centrum',      'PL', 48200.0,  3120,  4180, 'PLN'],
        ['pl-galeria',   'Merisù Galeria',      'PL', 39750.0,  2740,  3390, 'PLN'],
        ['pl-stare',     'Merisù Stare Miasto', 'PL', 51400.0,  2980,  4520, 'PLN'],
        ['pl-dworzec',   'Merisù Dworzec',      'PL', 27300.0,  3640,  2870, 'PLN'],
        ['it-navigli',   'Merisù Navigli',      'IT', 12800.0,  1180,  1640, 'EUR'],
        ['it-trastevere', 'Merisù Trastevere',  'IT', 15900.0,   980,  1720, 'EUR'],
        ['fr-marais',    'Merisù Marais',       'FR', 14350.0,  1040,  1490, 'EUR'],
        ['es-gracia',    'Merisù Gràcia',       'ES', 11200.0,   890,  1310, 'EUR'],
    ];

    public function __construct(
        /**
         * Boutique du poste courant. Dans la vraie implémentation, elle se
         * déduira du poste ou de la fiche du consultant.
         */
        private readonly string $currentShopId = 'pl-centrum',
    ) {
    }

    public function performances(string $from, string $to): array
    {
        // La période est ignorée : ces chiffres sont fixes, et le seront tant
        // que la caisse n'est pas branchée. Une implémentation réelle DOIT en
        // tenir compte, sinon le sélecteur de période ne veut plus rien dire.
        return array_map(
            static fn (array $l): ShopPerformance => new ShopPerformance(
                $l[0], $l[1], $l[2], $l[3], $l[4], $l[5], $l[6],
            ),
            self::DEMO,
        );
    }

    public function currentShopId(): ?string
    {
        return $this->currentShopId;
    }
}
