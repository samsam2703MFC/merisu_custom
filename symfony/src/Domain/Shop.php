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
        public bool $active = true,
        public int $sortOrder = 0,
    ) {
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
            $active ?? $this->active,
            $sortOrder ?? $this->sortOrder,
        );
    }
}
