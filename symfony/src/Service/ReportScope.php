<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Domain\ReportPerimeter;
use Merisu\Inventory\Domain\Shop;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Store\ConsultantStore;
use Merisu\Inventory\Store\ShopStore;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le périmètre d'un rapport, branché sur la requête et sur la base.
 *
 * La RÈGLE vit dans `ReportPerimeter`, où elle se prouve sans navigateur ;
 * cette classe ne fait que l'alimenter — la personne connectée, les boutiques,
 * les postes, et la boutique demandée dans l'URL.
 *
 * Le filtre s'écrivait déjà deux fois, une fois dans Météo × ventes et une
 * fois dans Objectifs. Les deux validaient la boutique demandée, mais l'un
 * rendait un CODE et l'autre un IDENTIFIANT, l'un acceptait « toutes » et
 * l'autre retombait sur la première. Le troisième rapport aurait inventé une
 * troisième règle, et le quatrième aurait oublié le contrôle.
 */
final readonly class ReportScope
{
    public function __construct(
        private CurrentUser $currentUser,
        private ShopStore $shops,
        private ConsultantStore $consultants,
    ) {
    }

    /**
     * Le périmètre de la personne connectée.
     *
     * Personne de connectée : un périmètre VIDE, jamais complet — le repli
     * d'une règle d'accès doit fermer et non ouvrir.
     */
    public function perimeter(): ReportPerimeter
    {
        return new ReportPerimeter($this->currentUser->consultant(), $this->shops->all());
    }

    /** @return list<Shop> */
    public function shops(): array
    {
        return $this->perimeter()->shops();
    }

    /** La boutique choisie, ou `null` pour « toutes celles que je pilote ». */
    public function selected(Request $request): ?Shop
    {
        return $this->perimeter()->resolve((string) (
            $request->query->get('boutique')
            ?? $request->request->get('boutique')
            ?? ''
        ));
    }

    /**
     * Ce qu'il faut passer à `Store::sales()` : des codes, ou `null`.
     *
     * `null` veut dire « ne filtre pas » et n'est rendu qu'à un administrateur
     * qui n'a rien choisi — lui seul doit voir aussi les remontées d'avant le
     * réseau, qui ne portent aucun code.
     *
     * @return list<string>|null
     */
    public function salesFilter(Request $request): ?array
    {
        $perimetre = $this->perimeter();
        $choisie = $this->selected($request);

        return $perimetre->filtersSales($choisie) ? $perimetre->codes($choisie) : null;
    }

    /** @return list<string> */
    public function workstationIds(Request $request): array
    {
        return $this->perimeter()->workstationIds(
            $this->selected($request),
            $this->consultants->workstations(),
        );
    }
}
