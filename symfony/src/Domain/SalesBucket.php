<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une case de l'analyse : ce qui s'est vendu sous une clé donnée.
 *
 * ── La MOYENNE compte autant que le total
 *
 * « 380 tiramisus le samedi » ne veut rien dire tant qu'on ignore combien de
 * samedis l'intervalle contient. Sur six semaines, c'est 63 par samedi ; sur
 * une, c'est 380. Le total seul a fait produire six fois trop, et c'est
 * exactement le chiffre dont le stock minimum a besoin — celui d'UN samedi.
 *
 * `days` porte donc le nombre de journées RÉELLEMENT observées sous cette clé,
 * jamais le nombre théorique : une boutique fermée le lundi n'a pas de lundis
 * à moyenner, et diviser par un lundi fictif aurait abaissé la moyenne sans
 * raison.
 */
final readonly class SalesBucket
{
    public function __construct(
        public string $key,
        public float $quantity,
        public float $revenue,
        /** Nombre de journées distinctes observées sous cette clé. */
        public int $days,
    ) {
    }

    /**
     * Ce qui se vend un jour ordinaire de cette case.
     *
     * Arrondi au DIXIÈME : « 185,667 tiramisus par lundi » annonce une
     * précision que six lundis ne portent pas. Le dixième suffit à comparer
     * deux jours, et c'est tout ce qu'on demande à ce chiffre.
     */
    public function averagePerDay(): float
    {
        return $this->days > 0 ? round($this->quantity / $this->days, 1) : 0.0;
    }

    public function averageRevenuePerDay(): float
    {
        return $this->days > 0 ? round($this->revenue / $this->days, 2) : 0.0;
    }
}
