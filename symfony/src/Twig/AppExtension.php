<?php

declare(strict_types=1);

namespace Merisu\Inventory\Twig;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Adapter\LocalShopRankingService;
use Merisu\Inventory\Adapter\Material;
use Merisu\Inventory\Adapter\ShopRankingServiceInterface;
use Merisu\Inventory\Adapter\Workstation;
use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ContainerQuantity;
use Merisu\Inventory\Domain\Locale;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Store\Store;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Fonctions Twig du module.
 *
 * Distinction importante : `label()` sert aux LIBELLÉS DE DONNÉES (noms de
 * produits et de matières, saisis en administration), tandis que `trans` de
 * Symfony sert aux textes d'interface. Les deux ne vivent pas au même endroit.
 */
final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CurrentUser $currentUser,
        private readonly Store $store,
        private readonly ConsultantServiceInterface $consultants,
        private readonly ShopRankingServiceInterface $ranking,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('product_label', $this->productLabel(...)),
            new TwigFunction('material_label', $this->materialLabel(...)),
            new TwigFunction('checklist_text', $this->checklistText(...)),
            new TwigFunction('current_user', fn () => $this->currentUser),
            new TwigFunction('app_settings', fn () => $this->store->settings()),
            new TwigFunction('supported_locales', static fn (): array => Locale::all()),
            new TwigFunction('available_workstations', $this->availableWorkstations(...)),
            new TwigFunction('workstation_name', $this->workstationName(...)),
            new TwigFunction('container_fractions', static fn (): array => ContainerQuantity::FRACTIONS),
            new TwigFunction('container_split', static fn (?float $q): array => ContainerQuantity::split($q)),
            // Vrai tant que la caisse n'est pas branchée : l'écran Réseau le
            // dit franchement plutôt que de laisser croire à de vraies mesures.
            new TwigFunction('is_demo_ranking', fn (): bool => $this->ranking instanceof LocalShopRankingService),
        ];
    }

    /**
     * Postes proposés dans le menu de l'avatar.
     *
     * L'administrateur voit tous les postes ; le vendeur ne voit que ceux
     * auxquels le module Consultant l'a rattaché. Sans rattachement connu, on
     * retombe sur la liste complète : mieux vaut un choix large qu'un menu vide
     * qui empêcherait de saisir.
     *
     * @return list<Workstation>
     */
    public function availableWorkstations(): array
    {
        $consultant = $this->currentUser->consultant();

        if ($consultant === null) {
            return [];
        }

        $all = $this->consultants->workstations();

        if ($consultant->role->isAdmin() || $consultant->workstations === []) {
            return $all;
        }

        $allowed = array_values(array_filter(
            $all,
            static fn (Workstation $w): bool => \in_array($w->id, $consultant->workstations, true),
        ));

        return $allowed !== [] ? $allowed : $all;
    }

    /** Nom lisible d'un poste : les écrans reçoivent des identifiants. */
    public function workstationName(?string $id, string $placeholder = '—'): string
    {
        if ($id === null || $id === '') {
            return $placeholder;
        }

        return $this->consultants->workstation($id)?->name ?? $id;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('qty', $this->formatQty(...)),
            new TwigFilter('pct', $this->formatPct(...)),
            new TwigFilter('datetime_short', $this->formatDateTime(...)),
            new TwigFilter('time_short', $this->formatTime(...)),
            new TwigFilter('long_date', $this->formatLongDate(...)),
        ];
    }

    public function productLabel(Product $product): string
    {
        return $product->label($this->locale(), $this->store->settings()->defaultLocale);
    }

    /** Libellé d'un point de check-list, même repli que les produits. */
    public function checklistText(ChecklistItem $item): string
    {
        return $item->text($this->locale(), $this->store->settings()->defaultLocale);
    }

    public function materialLabel(?Material $material, string $fallback = ''): string
    {
        return $material?->label($this->locale(), $this->store->settings()->defaultLocale) ?? $fallback;
    }

    /** Quantité sans décimale inutile : 18 et non 18,000. */
    public function formatQty(float|int|null $value, string $placeholder = '—'): string
    {
        if ($value === null) {
            return $placeholder;
        }

        $rounded = round((float) $value, 3);

        return $rounded == (int) $rounded
            ? number_format((float) $rounded, 0, ',', ' ')
            : rtrim(rtrim(number_format((float) $rounded, 3, ',', ' '), '0'), ',');
    }

    public function formatPct(?float $value, string $placeholder = '—'): string
    {
        return $value === null ? $placeholder : number_format($value * 100, 1, ',', ' ') . ' %';
    }

    public function formatDateTime(?string $iso, string $placeholder = '—'): string
    {
        if ($iso === null || $iso === '') {
            return $placeholder;
        }

        try {
            return (new \DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone($this->store->settings()->timezone))
                ->format('d/m/Y H:i');
        } catch (\Exception) {
            return $placeholder;
        }
    }

    public function formatTime(?string $iso, string $placeholder = '—'): string
    {
        if ($iso === null || $iso === '') {
            return $placeholder;
        }

        try {
            return (new \DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone($this->store->settings()->timezone))
                ->format('H:i');
        } catch (\Exception) {
            return $placeholder;
        }
    }

    /** Date métier au format long, dans la langue courante. */
    public function formatLongDate(string $isoDate): string
    {
        try {
            $date = new \DateTimeImmutable($isoDate . ' 12:00:00', new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $isoDate;
        }

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                $this->locale()->value,
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::NONE,
                'UTC',
            );

            return $formatter->format($date) ?: $date->format('d/m/Y');
        }

        // Sans l'extension intl, on reste sur un format neutre plutôt que
        // d'afficher un nom de jour en anglais.
        return $date->format('d/m/Y');
    }

    private function locale(): Locale
    {
        $request = $this->requestStack->getCurrentRequest();

        return Locale::tryFromLoose($request?->getLocale()) ?? Locale::Fr;
    }
}
