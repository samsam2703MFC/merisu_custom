<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Quand une ligne se compte : à quels moments, et combien de fois par semaine.
 *
 * Tout ne se compte pas au même rythme. Le tiramisu en vitrine se compte matin
 * ET soir, tous les jours : c'est de lui que dépend la production du lendemain.
 * Les gobelets ou le cacao se comptent une fois par semaine, le lundi, et les
 * faire apparaître aux quatorze écrans de la semaine ne fait qu'allonger une
 * liste que le vendeur parcourt debout, à 08:00.
 *
 * Deux réglages, donc, et pas un :
 *
 * · les MOMENTS — ouverture, clôture, ou les deux ;
 * · la FRÉQUENCE — 1, 2, 3, 4, 5 ou 7 fois par semaine.
 *
 * ── Quels jours, pour une fréquence donnée ────────────────────────────────
 *
 * L'administrateur choisit un NOMBRE, pas des jours : « deux fois par semaine »
 * est ce qu'il a en tête, et lui demander lesquels rouvrirait la matrice des
 * seuils pour un réglage qui n'en demande pas tant. Les jours en sont donc
 * déduits, et la règle est fixe pour rester prévisible :
 *
 * · 7 → tous les jours ;
 * · 5 → du lundi au vendredi. C'est ce que « cinq fois par semaine » veut dire
 *   dans une boutique — la semaine ouvrée — et non cinq jours étalés au
 *   hasard. C'est aussi pourquoi 6 n'est pas proposé : il ne veut rien dire ;
 * · 1 à 4 → étalés au plus large depuis le lundi, par `floor(i × 7 / n)`.
 *   Une fois = lundi. Deux fois = lundi et jeudi. Trois = lundi, mercredi,
 *   vendredi. Quatre = lundi, mardi, jeudi, samedi.
 *
 * La semaine démarre au lundi, comme la matrice des seuils.
 */
final readonly class CountSchedule
{
    /**
     * Fréquences proposées. Six est volontairement absent : « six jours sur
     * sept » ne correspond à aucun rythme de boutique, et il n'existe pas de
     * répartition qui se lise d'évidence.
     *
     * @var list<int>
     */
    public const FREQUENCIES = [1, 2, 3, 4, 5, 7];

    public function __construct(
        public bool $morning = true,
        public bool $evening = true,
        public int $frequency = 7,
    ) {
    }

    /**
     * Ramène une fréquence saisie à l'une des valeurs proposées.
     *
     * Repli sur 7 — tous les jours — et non sur 1 : une valeur illisible vient
     * d'une base ancienne ou d'une requête forgée, et compter plus souvent que
     * prévu ne fait perdre qu'un peu de temps, là où compter moins souvent
     * laisse le stock filer sans que personne le voie.
     */
    public static function cleanFrequency(mixed $value): int
    {
        $n = is_numeric($value) ? (int) $value : 0;

        return \in_array($n, self::FREQUENCIES, true) ? $n : 7;
    }

    /**
     * Construit un rythme à partir de valeurs brutes.
     *
     * Aucun moment coché revient à les cocher TOUS LES DEUX : une ligne qui ne
     * se compte à aucun moment disparaîtrait de tous les écrans sans rien dire,
     * et son stock cesserait d'exister aux yeux de l'application. Pour retirer
     * une ligne du comptage, on la désactive — c'est explicite et ça se voit.
     */
    public static function of(bool $morning, bool $evening, mixed $frequency): self
    {
        return new self(
            $morning || !$evening,
            $evening || !$morning,
            self::cleanFrequency($frequency),
        );
    }

    /**
     * Les jours où le comptage a lieu, dans l'ordre de la semaine.
     *
     * @return list<DayOfWeek>
     */
    public function days(): array
    {
        $semaine = DayOfWeek::all();

        if ($this->frequency >= 7) {
            return $semaine;
        }

        if ($this->frequency === 5) {
            // La semaine ouvrée, et non cinq jours étalés.
            return \array_slice($semaine, 0, 5);
        }

        $jours = [];
        for ($i = 0; $i < $this->frequency; ++$i) {
            $jours[] = $semaine[intdiv($i * 7, $this->frequency)];
        }

        return $jours;
    }

    public function countsAt(CountMoment $moment): bool
    {
        return $moment->isEvening() ? $this->evening : $this->morning;
    }

    /** La ligne est-elle à compter ce jour-là, à ce moment-là ? */
    public function isDue(DayOfWeek $day, CountMoment $moment): bool
    {
        return $this->countsAt($moment) && \in_array($day, $this->days(), true);
    }
}
