<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductNature;
use Merisu\Inventory\Domain\RecipeTemplate;
use Merisu\Inventory\Domain\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecipeTemplateTest extends TestCase
{
    /** @param array<string,string> $noms */
    private static function produit(string $id, array $noms, ProductNature $nature = ProductNature::Sale): Product
    {
        return new Product($id, strtoupper($id), $noms, 'pcs', true, 0.0, 1.0, RoundingMode::Ceil, null, 0, nature: $nature);
    }

    private static function modele(string $fragment, array $lignes = ['creme' => 100.0]): RecipeTemplate
    {
        return new RecipeTemplate('m1', 'Regular', $fragment, $lignes);
    }

    // ── Le rattachement ─────────────────────────────────────────────────────

    #[DataProvider('rattachements')]
    public function testCeQueLeFragmentAttrape(string $fragment, string $nom, bool $attendu): void
    {
        self::assertSame(
            $attendu,
            self::modele($fragment)->matches(self::produit('p', ['fr' => $nom])),
        );
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function rattachements(): iterable
    {
        yield 'la taille en fin de nom' => ['Regular', 'Traditional Regular', true];
        yield 'la casse ne compte pas' => ['regular', 'Traditional REGULAR', true];
        yield 'les espaces autour sont ignorés' => ['  Regular  ', 'Berries Regular', true];
        yield 'une autre taille' => ['Regular', 'Berries Grande', false];
        // « Grande » est contenu dans « Grande » mais pas dans « Grand » :
        // le fragment est cherché tel quel, sans tolérance.
        yield 'un fragment plus long que le nom' => ['Regular', 'Regul', false];
        yield 'un fragment au milieu' => ['& Nutella', 'Oreo & Nutella Extra', true];
    }

    /**
     * Un fragment VIDE n'attrape rien — et surtout pas tout.
     *
     * La chaîne vide est contenue dans n'importe quel nom : le modèle se
     * serait posé sur le catalogue entier au premier clic, emballages compris.
     */
    public function testUnFragmentVideNAttrapeRien(): void
    {
        self::assertFalse(self::modele('')->matches(self::produit('p', ['fr' => 'Traditional Regular'])));
        self::assertFalse(self::modele('   ')->matches(self::produit('p', ['fr' => 'Traditional Regular'])));
        self::assertFalse(self::modele('')->isUsable());
    }

    /**
     * Le fragment cherche dans TOUTES les langues.
     *
     * Une boutique dont l'écran est en polonais n'a pas forcément rempli le
     * français, et le fragment doit pouvoir viser la langue qu'on a sous les
     * yeux.
     */
    public function testLeFragmentCherchDansToutesLesLangues(): void
    {
        $produit = self::produit('p', ['pl' => 'Tradycyjne Regular', 'fr' => '']);

        self::assertTrue(self::modele('Regular')->matches($produit));
        self::assertTrue(self::modele('Tradycyjne')->matches($produit));
    }

    /**
     * Seul ce qui se FABRIQUE est visé.
     *
     * Poser une recette sur un sac en papier n'aurait aucun sens, et
     * l'admettre aurait fait du delta technique le comparatif d'un emballage
     * avec lui-même.
     */
    #[DataProvider('natures')]
    public function testSeulCeQuiSeFabriqueEstVise(ProductNature $nature, bool $attendu): void
    {
        $produit = self::produit('p', ['fr' => 'Sachet Regular'], $nature);

        self::assertSame($attendu, self::modele('Regular')->matches($produit));
    }

    /** @return iterable<string, array{ProductNature, bool}> */
    public static function natures(): iterable
    {
        yield 'produit en vente' => [ProductNature::Sale, true];
        yield 'recette' => [ProductNature::Recipe, true];
        yield 'matière première' => [ProductNature::Raw, false];
        yield 'emballage' => [ProductNature::Packaging, false];
    }

    public function testLesProduitsVisesSontRendusDansLOrdre(): void
    {
        $produits = [
            self::produit('a', ['fr' => 'Traditional Regular']),
            self::produit('b', ['fr' => 'Traditional Grande']),
            self::produit('c', ['fr' => 'Berries Regular']),
        ];

        self::assertSame(
            ['a', 'c'],
            array_map(static fn (Product $p): string => $p->id, self::modele('Regular')->targets($produits)),
        );
    }

    // ── Ce que le modèle écrit ──────────────────────────────────────────────

    /**
     * LE point : le modèle POSE ses matières et laisse les autres.
     *
     * Sans cette règle, poser un modèle « taille » aurait effacé la pâte de
     * pistache propre au parfum, et l'on aurait ressaisi à la main ce qu'on
     * venait d'automatiser.
     */
    public function testLeModelePoseSesMatieresEtLaisseLesAutres(): void
    {
        $modele = self::modele('Regular', ['creme' => 100.0, 'savoiardi' => 2.0]);

        self::assertSame(
            ['pistache' => 12.0, 'creme' => 100.0, 'savoiardi' => 2.0],
            $modele->applyTo(['pistache' => 12.0, 'creme' => 80.0]),
        );
    }

    /**
     * Appliquer deux fois donne le même résultat qu'une.
     *
     * Les lignes REMPLACENT, elles ne s'ajoutent pas : un clic de trop ne doit
     * pas doubler les quantités de tout le catalogue en silence.
     */
    public function testAppliquerDeuxFoisNeDoublePasLesQuantites(): void
    {
        $modele = self::modele('Regular', ['creme' => 100.0]);

        $unefois = $modele->applyTo([]);
        $deuxfois = $modele->applyTo($unefois);

        self::assertSame($unefois, $deuxfois);
        self::assertSame(['creme' => 100.0], $deuxfois);
    }

    /**
     * Une ligne à zéro RETIRE la matière.
     *
     * C'est l'absence de ligne qui dit « ce produit ne consomme pas cette
     * matière ». Cela donne au modèle le moyen d'annuler une ligne qu'il avait
     * posée, sans quoi une erreur de saisie serait irrattrapable en masse.
     */
    public function testUneLigneAZeroRetireLaMatiere(): void
    {
        $modele = self::modele('Regular', ['creme' => 0.0]);

        self::assertSame(['savoiardi' => 2.0], $modele->applyTo(['creme' => 100.0, 'savoiardi' => 2.0]));
    }

    public function testUnModeleSansMatiereNEstPasUtilisable(): void
    {
        self::assertFalse((new RecipeTemplate('m', 'Regular', 'Regular', []))->isUsable());
        self::assertTrue((new RecipeTemplate('m', 'Regular', 'Regular', ['creme' => 1.0]))->isUsable());
    }

    public function testLeFragmentEstBorne(): void
    {
        self::assertSame(
            RecipeTemplate::MATCH_MAX,
            mb_strlen(RecipeTemplate::cleanMatch(str_repeat('a', RecipeTemplate::MATCH_MAX + 50))),
        );
    }
}
