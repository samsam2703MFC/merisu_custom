<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ContainerType;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContainerTypeTest extends TestCase
{
    /** @return iterable<string, array{?string, ContainerType}> */
    public static function valeursSouples(): iterable
    {
        yield 'valeur exacte' => ['BUCKET', ContainerType::Bucket];
        yield 'minuscules' => ['bottle', ContainerType::Bottle];
        yield 'espaces autour' => ["  BOX \n", ContainerType::Box];
        yield 'casse mêlée' => ['jAr', ContainerType::Jar];
    }

    #[DataProvider('valeursSouples')]
    public function testLaLectureToleranteAccepteLesFormesUsuelles(?string $brut, ContainerType $attendu): void
    {
        self::assertSame($attendu, ContainerType::tryFromLoose($brut));
    }

    /** @return iterable<string, array{?string}> */
    public static function valeursInutilisables(): iterable
    {
        yield 'absente' => [null];
        yield 'vide' => [''];
        yield 'inconnue' => ['TONNEAU'];
        yield 'presque juste' => ['BUCKETS'];
    }

    /**
     * Une valeur inutilisable ne doit pas laisser la liste sans icône : le bac
     * est le repli, et il est explicite.
     */
    #[DataProvider('valeursInutilisables')]
    public function testUneValeurInutilisableSeReplieSurLeBac(?string $brut): void
    {
        self::assertNull(ContainerType::tryFromLoose($brut));
        self::assertSame(ContainerType::Tub, ContainerType::fromLoose($brut));
    }

    /**
     * Chaque forme doit avoir une icône distincte : deux formes partageant le
     * même dessin rendraient le repère visuel inutile.
     */
    public function testChaqueFormeADessinUnique(): void
    {
        $icones = array_map(static fn (ContainerType $t): string => $t->icon(), ContainerType::all());

        self::assertCount(\count(ContainerType::cases()), $icones);
        self::assertSame($icones, array_unique($icones));
    }

    /** L'énumération et la liste ordonnée ne doivent pas diverger. */
    public function testLaListeCouvreToutesLesFormes(): void
    {
        self::assertEqualsCanonicalizing(ContainerType::cases(), ContainerType::all());
    }

    /**
     * Un produit créé sans préciser de forme reste utilisable : le bac est la
     * valeur par défaut, jamais null.
     */
    public function testUnProduitSansFormePreciseeCompteEnBac(): void
    {
        self::assertSame(ContainerType::Tub, self::produit()->containerType);
    }

    /**
     * La forme survit à une modification portant sur un autre champ : `with()`
     * recopie tous les arguments, et en oublier un les décalerait en silence.
     */
    public function testLaFormeSurvitAUneModificationDUnAutreChamp(): void
    {
        $produit = self::produit()->with(containerType: ContainerType::Bottle);

        $renomme = $produit->with(name: ['fr' => 'Limonade']);

        self::assertSame(ContainerType::Bottle, $renomme->containerType);
        self::assertSame('Limonade', $renomme->name['fr']);
    }

    private static function produit(): Product
    {
        return new Product(
            'p1',
            'PRODUIT_1',
            ['fr' => 'Crème'],
            'bac',
            true,
            0.0,
            1.0,
            RoundingMode::Ceil,
            null,
            1,
        );
    }
}
