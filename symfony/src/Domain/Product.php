<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Produit — un des 8 emplacements génériques, renommables et activables en admin.
 *
 * Aucune valeur produit n'est codée dans l'application : tout vient de la base.
 */
final readonly class Product
{
    /**
     * @param array<string,string> $name Libellé par langue : ['fr' => 'Crème 1', 'pl' => …]
     */
    public function __construct(
        public string $id,
        /** Code stable PRODUIT_1 … PRODUIT_8, jamais affiché à l'utilisateur. */
        public string $code,
        public array $name,
        public string $unit,
        public bool $active,
        /** Facteur de perte : 0.05 = +5 % produits en plus. */
        public float $wasteFactor,
        public float $roundingStep,
        public RoundingMode $roundingMode,
        /** Référence du produit dans le module « Recette technique » existant. */
        public ?string $recipeRef,
        public int $sortOrder,
        /**
         * Unité ou contenant. Le mode conteneur change la saisie à l'écran,
         * jamais le calcul : la quantité reste décimale en base.
         */
        public CountMode $countMode = CountMode::Pieces,
        /** Catégorie de production, libre et administrable. Vide = non classé. */
        public string $category = '',
        /**
         * Durée de vie en jours, à partir du jour de production. L'étiquette
         * en déduit la DLC. 0 = non renseignée : aucune date n'est imprimée
         * plutôt qu'une date fausse, qui engagerait la responsabilité de la
         * boutique.
         */
        public int $shelfLifeDays = 0,
        /** @var array<string,string> Ingrédients par langue, tels qu'affichés. */
        public array $ingredients = [],
        /**
         * @var array<string,string> Allergènes par langue.
         *
         * Séparés des ingrédients bien qu'ils en fassent partie : la
         * réglementation impose de les faire ressortir, et l'étiquette les
         * imprime donc à part, en évidence.
         */
        public array $allergens = [],
        /**
         * Forme du contenant, quand le produit se compte par contenant.
         *
         * Sans effet sur le calcul, et sans effet du tout en mode « à
         * l'unité » : le réglage reste stocké pour qu'un aller-retour entre
         * les deux modes ne perde pas le choix déjà fait.
         */
        public ContainerType $containerType = ContainerType::Tub,
        /**
         * Ce que la ligne EST : produit en vente, préparation, matière
         * première ou emballage.
         *
         * Seul ce qui se FABRIQUE entre au plan de production : demander une
         * quantité de mascarpone ou de barquettes « à produire » n'a aucun
         * sens. C'est aussi elle qui décide si la fiche peut porter une
         * nomenclature, et si elle peut entrer dans celle d'une autre.
         */
        public ProductNature $nature = ProductNature::Sale,
        /**
         * Quand cette ligne se compte : à quels moments, et à quel rythme.
         *
         * Tout ne se compte pas matin et soir tous les jours. Les gobelets se
         * comptent le lundi ; les faire figurer aux quatorze écrans de la
         * semaine n'allonge qu'une liste parcourue debout, à 08:00.
         *
         * Matin et soir, tous les jours, par défaut : c'est le réglage qui ne
         * retire rien à personne.
         */
        public CountSchedule $schedule = new CountSchedule(),
        /**
         * D'où la ligne s'approvisionne : la centrale du réseau, ou un
         * fournisseur choisi par la boutique.
         */
        public SupplierSource $supplierSource = SupplierSource::Central,
        /**
         * Référence chez le fournisseur.
         *
         * Distincte de `recipeRef`, qui identifie le produit dans le système
         * hôte : l'une sert à COMMANDER, l'autre à remonter un comptage. Les
         * confondre aurait marché tant que les deux coïncident, et cassé le
         * jour où une boutique change de fournisseur.
         */
        public string $supplierRef = '',
        /** Nom du fournisseur — n'a de sens qu'en approvisionnement libre. */
        public string $supplierName = '',
        /**
         * Prix d'ACHAT, par unité de mesure — le kilo, le litre, la pièce.
         *
         * Il ne vaut que pour ce qu'on ACHÈTE : matière première et emballage.
         * Une recette et un produit en vente tirent le leur de leur
         * composition, et l'y saisir à la main aurait créé deux vérités qui
         * divergent dès la première hausse du mascarpone.
         *
         * 0 = non renseigné, et c'est dit à l'écran plutôt que compté comme
         * gratuit : un coût matière amputé d'un ingrédient est plus dangereux
         * qu'un coût absent, parce qu'il a l'air juste.
         */
        public float $unitCost = 0.0,
    ) {
    }

    /** Se fabrique-t-il ? Raccourci de lecture pour les gabarits. */
    public function isProduced(): bool
    {
        return $this->nature->isProduced();
    }

    /** Ingrédients dans la langue demandée, avec le même repli que le libellé. */
    public function ingredientsText(Locale $locale, Locale $default = Locale::Fr): string
    {
        return self::pick($this->ingredients, $locale, $default);
    }

    /** Allergènes dans la langue demandée, avec le même repli que le libellé. */
    public function allergensText(Locale $locale, Locale $default = Locale::Fr): string
    {
        return self::pick($this->allergens, $locale, $default);
    }

    /**
     * Langue demandée → langue par défaut → première renseignée → vide.
     *
     * Vide et non le code du produit, contrairement au libellé : une liste
     * d'ingrédients absente ne s'imprime pas, alors qu'un identifiant technique
     * imprimé à sa place serait illisible et pourrait passer pour une mention
     * réglementaire.
     *
     * @param array<string,string> $valeurs
     */
    private static function pick(array $valeurs, Locale $locale, Locale $default): string
    {
        foreach ([$locale->value, $default->value] as $candidat) {
            $valeur = trim($valeurs[$candidat] ?? '');
            if ($valeur !== '') {
                return $valeur;
            }
        }

        foreach (Locale::all() as $repli) {
            $valeur = trim($valeurs[$repli->value] ?? '');
            if ($valeur !== '') {
                return $valeur;
            }
        }

        return '';
    }

    /**
     * Libellé dans la langue demandée, avec repli en cascade :
     * langue demandée → langue par défaut → première langue renseignée → code.
     */
    public function label(Locale $locale, Locale $default = Locale::Fr): string
    {
        foreach ([$locale->value, $default->value] as $candidate) {
            $value = trim($this->name[$candidate] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        foreach (Locale::all() as $fallback) {
            $value = trim($this->name[$fallback->value] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return $this->code;
    }

    public function with(mixed ...$changes): self
    {
        return new self(
            $changes['id'] ?? $this->id,
            $changes['code'] ?? $this->code,
            $changes['name'] ?? $this->name,
            $changes['unit'] ?? $this->unit,
            $changes['active'] ?? $this->active,
            $changes['wasteFactor'] ?? $this->wasteFactor,
            $changes['roundingStep'] ?? $this->roundingStep,
            $changes['roundingMode'] ?? $this->roundingMode,
            \array_key_exists('recipeRef', $changes) ? $changes['recipeRef'] : $this->recipeRef,
            $changes['sortOrder'] ?? $this->sortOrder,
            $changes['countMode'] ?? $this->countMode,
            $changes['category'] ?? $this->category,
            $changes['shelfLifeDays'] ?? $this->shelfLifeDays,
            $changes['ingredients'] ?? $this->ingredients,
            $changes['allergens'] ?? $this->allergens,
            $changes['containerType'] ?? $this->containerType,
            $changes['nature'] ?? $this->nature,
            $changes['schedule'] ?? $this->schedule,
            $changes['supplierSource'] ?? $this->supplierSource,
            $changes['supplierRef'] ?? $this->supplierRef,
            $changes['supplierName'] ?? $this->supplierName,
            $changes['unitCost'] ?? $this->unitCost,
        );
    }
}
