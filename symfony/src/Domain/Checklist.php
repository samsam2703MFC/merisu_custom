<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Une check-list — un volet de points à signer.
 *
 * ── De trois volets FIGÉS à des DONNÉES
 *
 * Ouverture, fermeture et contrôle qualité étaient une énumération : en
 * ajouter un — « réception marchandises », « nettoyage hebdo » — demandait un
 * développeur et un déploiement. C'est pourtant un vocabulaire d'atelier,
 * comme les catégories de produits : il appartient à l'administrateur, pas au
 * code. Les trois volets historiques deviennent les trois premières lignes de
 * la table, à l'identifiant près — les points déjà signés les retrouvent sans
 * migration.
 *
 * ── Ce qu'une check-list PORTE, et ce qu'elle ne porte pas
 *
 * Un nom par langue — c'est une donnée, comme les libellés de produits, pas
 * une chaîne d'interface. Une heure indicative, celle où l'équipe s'y met.
 * Une icône, choisie dans la petite liste que l'application connaît.
 *
 * Elle ne porte PAS ses règles de signature : le code, la photo, la note, le
 * statut appartiennent aux POINTS et au geste de signer, qui ne changent pas.
 */
final readonly class Checklist
{
    public function __construct(
        public string $id,
        /** @var array<string, string> nom par langue */
        public array $name,
        public string $icon = 'checklist',
        /**
         * L'heure INDICATIVE du volet, au format HH:MM. Vide : pas d'heure —
         * le contrôle qualité se fait quand la production le demande.
         */
        public string $executionTime = '',
        public int $sortOrder = 0,
        public bool $active = true,
    ) {
    }

    /** Nom dans la langue demandée, avec le même repli que les produits. */
    public function text(Locale $locale, Locale $default = Locale::Fr): string
    {
        foreach ([$locale->value, $default->value] as $candidate) {
            $valeur = trim($this->name[$candidate] ?? '');

            if ($valeur !== '') {
                return $valeur;
            }
        }

        foreach ($this->name as $valeur) {
            if (trim((string) $valeur) !== '') {
                return trim((string) $valeur);
            }
        }

        return $this->id;
    }

    public function hasExecutionTime(): bool
    {
        return trim($this->executionTime) !== '';
    }

    /** Les icônes proposées à l'éditeur — celles que l'application dessine. */
    public static function icons(): array
    {
        return ['checklist', 'sunrise', 'moon', 'shield', 'clipboard', 'tray', 'camera', 'book'];
    }

    public function with(mixed ...$changes): self
    {
        return new self(
            $changes['id'] ?? $this->id,
            $changes['name'] ?? $this->name,
            $changes['icon'] ?? $this->icon,
            $changes['executionTime'] ?? $this->executionTime,
            $changes['sortOrder'] ?? $this->sortOrder,
            $changes['active'] ?? $this->active,
        );
    }
}
