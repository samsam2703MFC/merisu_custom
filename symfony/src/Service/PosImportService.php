<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Merisu\Inventory\Adapter\PosServiceInterface;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductCategory;
use Merisu\Inventory\Domain\ProductNature;
use Merisu\Inventory\Domain\RoundingMode;
use Merisu\Inventory\Store\Store;

/**
 * Reprendre le catalogue de la caisse — depuis l'écran ou depuis le terminal.
 *
 * ── Pourquoi ce n'est plus dans le contrôleur
 *
 * Un import de quarante articles se fait aussi bien par SSH, sur un serveur
 * où personne n'ouvrira de navigateur — après une réinstallation, ou quand la
 * boutique vient d'ajouter vingt références et qu'on veut les reprendre sans
 * déranger l'atelier. Recopier la règle dans une commande l'aurait fait
 * diverger de l'écran au premier ajustement, et l'on aurait eu deux imports
 * qui ne rangent pas pareil.
 *
 * ── L'import AJOUTE, il n'écrase pas
 *
 * Un produit déjà connu garde son unité, son facteur de perte, son rythme de
 * comptage et ses traductions : ce sont des réglages posés ici, que la caisse
 * ne connaît pas et ne peut donc pas remplacer. Seul le rattachement est mis
 * à jour. Sans cette règle, un import aurait remis à zéro le paramétrage de
 * toute la boutique.
 */
final readonly class PosImportService
{
    public function __construct(
        private PosServiceInterface $pos,
        private Store $store,
    ) {
    }

    /**
     * Reprend les catégories dans Admin ▸ Catégories.
     *
     * Les nouvelles seulement : une catégorie déjà présente porte peut-être
     * une nature réglée à la main (matière première, emballage), et la
     * réécrire l'aurait remise en « produit en vente ».
     *
     * @return array{seen: int, added: int}
     *
     * @throws \Merisu\Inventory\Adapter\PosUnavailable
     */
    public function importCategories(?string $actorId = null, ?string $actorRole = null): array
    {
        $distantes = $this->pos->categories();

        $connues = array_flip($this->store->categoryOrder());
        $ajoutees = 0;

        foreach ($distantes as $categorie) {
            $nom = ProductCategory::clean($categorie->name);

            if ($nom === '' || isset($connues[$nom])) {
                continue;
            }

            $this->store->addCategory($nom, ProductNature::Sale);
            $connues[$nom] = true;
            ++$ajoutees;
        }

        $bilan = ['seen' => count($distantes), 'added' => $ajoutees];

        if ($actorId !== null && $actorRole !== null) {
            $this->store->audit($actorId, $actorRole, 'POS_CATEGORIES_IMPORTED', null, null, $bilan);
        }

        return $bilan;
    }

    /**
     * Rattache les fiches produits aux articles de la caisse.
     *
     * Il RATTACHE : la fiche locale reçoit la référence de l'article
     * (`recipeRef`), seul lien durable entre les deux — un nom se retape, un
     * identifiant non. Et il crée les fiches absentes.
     *
     * @return array{seen: int, created: int, linked: int}
     *
     * @throws \Merisu\Inventory\Adapter\PosUnavailable
     */
    public function importItems(?string $actorId = null, ?string $actorRole = null): array
    {
        $articles = $this->pos->items();

        $parReference = [];
        $parNom = [];

        foreach ($this->store->products() as $produit) {
            if (($produit->recipeRef ?? '') !== '') {
                $parReference[(string) $produit->recipeRef] = $produit;
            }

            foreach ($produit->name as $libelle) {
                $parNom[mb_strtolower(trim($libelle))] = $produit;
            }
        }

        $crees = 0;
        $rattaches = 0;

        foreach ($articles as $article) {
            if (isset($parReference[$article->externalId])) {
                continue;
            }

            // Rattachement par le NOM, faute de référence : c'est le seul
            // repère disponible au premier import, et il évite de créer un
            // doublon de chaque produit déjà saisi à la main. Une fois
            // rattachée, la fiche ne dépend plus de son nom.
            $existante = $parNom[mb_strtolower($article->name)] ?? null;

            if ($existante !== null) {
                $this->store->saveProduct($existante->with(recipeRef: $article->externalId));
                $parReference[$article->externalId] = $existante;
                ++$rattaches;

                continue;
            }

            $slot = $this->store->nextProductSlot();

            $this->store->saveProduct(new Product(
                $slot['id'],
                $slot['code'],
                // Le nom part dans la langue par défaut : la caisse n'en donne
                // qu'un. Les trois autres se complètent au bouton « Traduire »
                // d'Admin ▸ Produits.
                [$this->store->settings()->defaultLocale->value => $article->name],
                'pcs',
                $article->enabled,
                0.0,
                1.0,
                RoundingMode::Ceil,
                $article->externalId,
                $slot['sortOrder'],
                category: ProductCategory::clean($article->categoryName ?? ''),
            ));

            ++$crees;
        }

        $bilan = ['seen' => count($articles), 'created' => $crees, 'linked' => $rattaches];

        if ($actorId !== null && $actorRole !== null) {
            $this->store->audit($actorId, $actorRole, 'POS_ITEMS_IMPORTED', null, null, $bilan);
        }

        return $bilan;
    }
}
