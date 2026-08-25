<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * L'écart entre deux périodes — la tendance.
 *
 * ── « Combien ? » ne suffit pas
 *
 * « 12 594 unités » ne dit ni si c'est bien ni si ça monte. Comparé aux six
 * semaines précédentes, le même chiffre devient une nouvelle : +8 %, ou −14 %.
 * C'est ce qui fait d'un tableau de bord autre chose qu'un relevé.
 *
 * ── Comparer à une période de MÊME longueur
 *
 * Six semaines contre six semaines. Comparer un mois entamé à un mois complet
 * aurait annoncé une chute tous les premiers du mois, et l'on aurait fini par
 * ne plus regarder l'indicateur.
 */
final class SalesTrend
{
    /**
     * La variation, en pourcentage.
     *
     * Rend NULL quand la période de référence est vide : on ne peut pas dire
     * de combien de pour cent on a progressé depuis rien. Rendre +100 % —
     * ou pire, +∞ — aurait affiché une envolée le lendemain de l'installation,
     * quand la seule nouveauté est qu'on a commencé à relever.
     */
    public static function change(float $now, float $before): ?float
    {
        if ($before <= 0.0 || !is_finite($before) || !is_finite($now)) {
            return null;
        }

        return Rounding::clean(($now - $before) / $before * 100);
    }

    /**
     * La période de MÊME longueur qui précède immédiatement celle-ci.
     *
     * @return array{from: string, to: string}
     */
    public static function previous(string $from, string $to): array
    {
        // Le nombre de journées de l'intervalle, bornes comprises. `range`
        // les énumère déjà, et s'en servir évite une seconde arithmétique de
        // dates qui aurait pu diverger de la première d'un jour.
        $jours = max(1, count(BusinessDate::range($from, $to)));

        return [
            'from' => BusinessDate::addDays($from, -$jours),
            'to' => BusinessDate::addDays($from, -1),
        ];
    }
}
