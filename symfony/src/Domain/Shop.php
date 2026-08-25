<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une boutique du réseau.
 *
 * ── Ce qui est PROPRE à une boutique, et ce qui ne l'est pas
 *
 * Propre : l'adresse, les coordonnées — deux boutiques n'ont pas la même
 * météo —, la caisse, les équipes, les comptages, les seuils.
 *
 * Commun : le catalogue et les compositions. Une enseigne vend la même gamme
 * partout ; donner un catalogue à chaque boutique aurait obligé à saisir trois
 * fois le même tiramisu, et le premier renommage en aurait laissé deux en
 * arrière. Là où les boutiques diffèrent, c'est sur les QUANTITÉS, et
 * celles-là sont déjà par boutique.
 *
 * ── Le code est la clé stable
 *
 * Le nom se retape — « Rynek » devient « Wrocław Rynek » un jour où l'on ouvre
 * la deuxième. Le code, lui, ne bouge pas : c'est lui que porteront les
 * comptages et les remontées, et un comptage rattaché à un nom aurait changé
 * de boutique le jour de la correction.
 */
final readonly class Shop
{
    public const CODE_MAX = 32;

    public function __construct(
        public string $id,
        /** Clé stable, jamais montrée au vendeur. */
        public string $code,
        public string $name,
        public string $address = '',
        public string $postalCode = '',
        public string $city = '',
        /**
         * Les coordonnées de la boutique.
         *
         * C'est la météo qui les demande : One Call ne connaît pas les
         * boutiques, il connaît des points. Deux boutiques à trois cents
         * kilomètres n'ont pas la même semaine, et une prévision unique aurait
         * fait produire à Wrocław selon le ciel de Varsovie.
         */
        public float $latitude = 0.0,
        public float $longitude = 0.0,
        /**
         * L'organisation GoPOS de CETTE boutique.
         *
         * Chaque paire d'identifiants de caisse est liée à une organisation au
         * moment où on la génère : une boutique, une organisation. Le numéro
         * vit donc ici, à côté de l'adresse, et non dans un réglage unique.
         */
        public string $posOrganizationId = '',
        /**
         * Les identifiants de caisse de cette boutique.
         *
         * Le secret est CHIFFRÉ en base et n'est jamais réaffiché — même règle
         * que partout ailleurs : « …4f2a » suffit à confirmer à qui l'a volé
         * qu'il tient le bon.
         */
        #[\SensitiveParameter]
        public string $posClientId = '',
        #[\SensitiveParameter]
        public string $posClientSecret = '',
        /*
          ── Les paramètres d'EXPLOITATION, par boutique

          Les horaires, la politique photo, la tolérance du delta et l'objectif
          du mois ne sont pas des réglages d'installation : ce sont des
          décisions de boutique. Wrocław ouvre à 8 h et Kraków à 9 h ; l'une
          exige une photo par produit, l'autre non.

          Tenus une seule fois pour tout le réseau, ils obligeaient la deuxième
          boutique à vivre avec les horaires de la première.
        */
        public string $openingTime = '08:00',
        public string $closingTime = '22:00',
        /** Fuseau IANA. Deux boutiques d'un même réseau peuvent en changer. */
        public string $timezone = 'Europe/Warsaw',
        public bool $photoRequired = false,
        public bool $photoPerProduct = false,
        /** Tolérance du delta technique : 0.05 = 5 %. */
        public float $deltaTolerance = 0.05,
        /** Objectif de tiramisu du mois. 0 = aucun objectif, pas de jauge. */
        public int $monthlyTarget = 0,
        public bool $active = true,
        public int $sortOrder = 0,
    ) {
    }

    /**
     * Ce que l'écran a le droit de montrer.
     *
     * Le secret de caisse n'en fait PAS partie, jamais, même tronqué. On dit
     * seulement qu'il est posé.
     */
    public function hasPosSecret(): bool
    {
        return trim($this->posClientSecret) !== '';
    }

    /** La caisse de cette boutique est-elle réglée de bout en bout ? */
    public function hasPos(): bool
    {
        return trim($this->posOrganizationId) !== ''
            && trim($this->posClientId) !== ''
            && $this->hasPosSecret();
    }

    /**
     * Un code utilisable.
     *
     * Majuscules, chiffres et tiret bas : il voyage dans des URL, des noms de
     * fichiers d'export et des charges utiles envoyées à l'hôte. Un espace ou
     * un accent s'y encode différemment selon le chemin emprunté, et deux
     * encodages du même code auraient fait deux boutiques.
     */
    public static function cleanCode(string $code): string
    {
        $propre = strtoupper(trim($code));
        $propre = preg_replace('/[^A-Z0-9_]+/', '_', $propre) ?? '';
        $propre = trim($propre, '_');

        return mb_substr($propre, 0, self::CODE_MAX);
    }

    /**
     * Des coordonnées utilisables.
     *
     * Le point (0, 0) est REFUSÉ : il tombe dans le golfe de Guinée, et c'est
     * exactement ce que rend un formulaire dont les deux champs sont restés
     * vides. Une boutique sans coordonnées est une boutique sans météo — pas
     * une boutique au large de l'Afrique.
     */
    public function hasCoordinates(): bool
    {
        return abs($this->latitude) <= 90.0
            && abs($this->longitude) <= 180.0
            && (abs($this->latitude) > 0.0001 || abs($this->longitude) > 0.0001);
    }

    /** L'adresse en une ligne, pour l'écran. Vide si rien n'est renseigné. */
    public function addressLine(): string
    {
        $morceaux = array_filter([
            trim($this->address),
            trim(trim($this->postalCode) . ' ' . trim($this->city)),
        ], static fn (string $m): bool => $m !== '');

        return implode(', ', $morceaux);
    }

    public function with(
        ?string $name = null,
        ?string $address = null,
        ?string $postalCode = null,
        ?string $city = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $posOrganizationId = null,
        ?string $posClientId = null,
        ?string $posClientSecret = null,
        ?string $openingTime = null,
        ?string $closingTime = null,
        ?string $timezone = null,
        ?bool $photoRequired = null,
        ?bool $photoPerProduct = null,
        ?float $deltaTolerance = null,
        ?int $monthlyTarget = null,
        ?bool $active = null,
        ?int $sortOrder = null,
    ): self {
        return new self(
            $this->id,
            $this->code,
            $name ?? $this->name,
            $address ?? $this->address,
            $postalCode ?? $this->postalCode,
            $city ?? $this->city,
            $latitude ?? $this->latitude,
            $longitude ?? $this->longitude,
            $posOrganizationId ?? $this->posOrganizationId,
            $posClientId ?? $this->posClientId,
            // Le secret n'est REMPLACÉ que si l'on en donne un : laissé vide,
            // il reste. C'est la contrepartie d'un champ qu'on n'affiche
            // jamais — sans cette règle, corriger une adresse aurait effacé un
            // secret que personne ne peut relire pour le retaper.
            $posClientSecret ?? $this->posClientSecret,
            $openingTime ?? $this->openingTime,
            $closingTime ?? $this->closingTime,
            $timezone ?? $this->timezone,
            $photoRequired ?? $this->photoRequired,
            $photoPerProduct ?? $this->photoPerProduct,
            $deltaTolerance ?? $this->deltaTolerance,
            $monthlyTarget ?? $this->monthlyTarget,
            $active ?? $this->active,
            $sortOrder ?? $this->sortOrder,
        );
    }
}
