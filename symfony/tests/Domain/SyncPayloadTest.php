<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\CountMoment;
use Merisu\Inventory\Domain\Product;
use Merisu\Inventory\Domain\ProductNature;
use Merisu\Inventory\Domain\RoundingMode;
use Merisu\Inventory\Domain\SyncKind;
use Merisu\Inventory\Domain\SyncPayload;
use PHPUnit\Framework\TestCase;

final class SyncPayloadTest extends TestCase
{
    private static function produit(
        string $id,
        ?string $reference,
        ProductNature $nature = ProductNature::Sale,
        string $unit = 'pcs',
    ): Product {
        return new Product($id, strtoupper($id), ['fr' => $id], $unit, true, 0.0, 1.0,
            RoundingMode::Ceil, $reference, 1, nature: $nature);
    }

    /** @param list<Product> $produits */
    private static function lignes(array $produits, array $quantites): array
    {
        return SyncPayload::forCounts(
            $produits,
            $quantites,
            '2026-08-07',
            'ws1',
            CountMoment::Close2200,
            'acteur-1',
            'shop-42',
        );
    }

    // ── Constitution des lignes ─────────────────────────────────────────────

    public function testUneLigneEmporteToutCeQueLHotePeutDemander(): void
    {
        $r = self::lignes([self::produit('p1', 'TFB-77', unit: 'g')], ['p1' => 12.5]);

        self::assertCount(1, $r['ready']);
        self::assertSame([
            'shopId' => 'shop-42',
            'workstationId' => 'ws1',
            'externalProductId' => 'TFB-77',
            'productId' => 'p1',
            'businessDate' => '2026-08-07',
            'moment' => 'CLOSE_2200',
            'qty' => 12.5,
            'unit' => 'g',
            'nature' => 'SALE',
            'validatedBy' => 'acteur-1',
        ], $r['ready'][0]);
    }

    /**
     * Sans référence hôte, la ligne partirait vers un produit inconnu, serait
     * refusée, et huit tentatives plus tard un comptage réel finirait « en
     * échec » pour une case laissée vide en administration.
     */
    public function testUnProduitSansReferenceHoteNePartPas(): void
    {
        $r = self::lignes([
            self::produit('p1', 'TFB-77'),
            self::produit('p2', null),
            self::produit('p3', '   '),
        ], ['p1' => 1.0, 'p2' => 2.0, 'p3' => 3.0]);

        self::assertCount(1, $r['ready']);
        self::assertSame(['p2', 'p3'], $r['missingRef']);
    }

    /** La référence est nettoyée : une saisie avec espaces reste utilisable. */
    public function testLaReferenceEstDebarrasseeDesEspaces(): void
    {
        $r = self::lignes([self::produit('p1', '  TFB-77 ')], ['p1' => 1.0]);

        self::assertSame('TFB-77', $r['ready'][0]['externalProductId']);
    }

    /** Un produit non compté ne remonte pas : rien ne s'invente à zéro. */
    public function testUnProduitNonCompteNeRemontePas(): void
    {
        $r = self::lignes([self::produit('p1', 'A'), self::produit('p2', 'B')], ['p1' => 4.0]);

        self::assertCount(1, $r['ready']);
        self::assertSame('p1', $r['ready'][0]['productId']);
        self::assertSame([], $r['missingRef']);
    }

    /** Une quantité nulle EST une information : le bac est vide. */
    public function testUneQuantiteNulleRemonteQuandMeme(): void
    {
        $r = self::lignes([self::produit('p1', 'A')], ['p1' => 0.0]);

        self::assertCount(1, $r['ready']);
        self::assertSame(0.0, $r['ready'][0]['qty']);
    }

    public function testSansBoutiqueConnueLaLignePartQuandMeme(): void
    {
        $r = SyncPayload::forCounts([self::produit('p1', 'A')], ['p1' => 1.0],
            '2026-08-07', 'ws1', CountMoment::Open0800, 'a1', null);

        self::assertNull($r['ready'][0]['shopId']);
        self::assertSame('OPEN_0800', $r['ready'][0]['moment']);
    }

    // ── Regroupement selon l'endroit où l'hôte les attend ───────────────────

    public function testCeQuiSeFabriquePartUnParUn(): void
    {
        $r = self::lignes([self::produit('p1', 'A'), self::produit('p2', 'B')], ['p1' => 1.0, 'p2' => 2.0]);

        $envois = SyncPayload::group($r['ready']);

        self::assertCount(2, $envois);
        foreach ($envois as $e) {
            self::assertSame(SyncKind::ProductInventory, $e['kind']);
        }
    }

    public function testCeQuiSAchetePartDUnBloc(): void
    {
        $r = self::lignes([
            self::produit('m1', 'MAT-1', ProductNature::Raw, 'g'),
            self::produit('m2', 'MAT-2', ProductNature::Raw, 'ml'),
        ], ['m1' => 500.0, 'm2' => 250.0]);

        $envois = SyncPayload::group($r['ready']);

        self::assertCount(1, $envois);
        self::assertSame(SyncKind::MaterialStocktaking, $envois[0]['kind']);
        self::assertSame([
            ['externalProductId' => 'MAT-1', 'qty' => 500.0, 'unit' => 'g'],
            ['externalProductId' => 'MAT-2', 'qty' => 250.0, 'unit' => 'ml'],
        ], $envois[0]['payload']['items']);
        self::assertSame('shop-42', $envois[0]['payload']['shopId']);
        self::assertSame('2026-08-07', $envois[0]['payload']['businessDate']);
    }

    /** L'emballage s'ACHÈTE : il part avec les matières, pas avec les desserts. */
    public function testUnEmballagePartAvecLesMatieres(): void
    {
        $r = self::lignes([
            self::produit('p1', 'A'),
            self::produit('b1', 'BARQ-1', ProductNature::Packaging, 'pcs'),
        ], ['p1' => 1.0, 'b1' => 300.0]);

        $envois = SyncPayload::group($r['ready']);

        self::assertSame(SyncKind::ProductInventory, $envois[0]['kind']);
        self::assertSame(SyncKind::MaterialStocktaking, $envois[1]['kind']);
        self::assertSame('BARQ-1', $envois[1]['payload']['items'][0]['externalProductId']);
    }

    /** Une RECETTE se fabrique : elle part une par une, comme un dessert. */
    public function testUneRecettePartCommeCeQuiSeFabrique(): void
    {
        $r = self::lignes([self::produit('r1', 'SUB-1', ProductNature::Recipe, 'l')], ['r1' => 8.0]);

        $envois = SyncPayload::group($r['ready']);

        self::assertCount(1, $envois);
        self::assertSame(SyncKind::ProductInventory, $envois[0]['kind']);
    }

    /** Un comptage panaché produit les deux formes, sans rien perdre. */
    public function testUnComptagePanacheProduitLesDeuxFormes(): void
    {
        $r = self::lignes([
            self::produit('p1', 'A'),
            self::produit('m1', 'MAT-1', ProductNature::Raw),
            self::produit('p2', 'B'),
            self::produit('m2', 'MAT-2', ProductNature::Raw),
        ], ['p1' => 1.0, 'm1' => 2.0, 'p2' => 3.0, 'm2' => 4.0]);

        $envois = SyncPayload::group($r['ready']);
        $natures = array_map(static fn (array $e): SyncKind => $e['kind'], $envois);

        self::assertSame([
            SyncKind::ProductInventory,
            SyncKind::ProductInventory,
            SyncKind::MaterialStocktaking,
        ], $natures);
        self::assertCount(2, $envois[2]['payload']['items']);
    }

    public function testAucuneLigneNeProduitAucunEnvoi(): void
    {
        self::assertSame([], SyncPayload::group([]));
    }
}
