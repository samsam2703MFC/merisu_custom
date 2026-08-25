<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Service;

use Merisu\Inventory\Service\PhotoStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le type d'une image se lit dans ses OCTETS.
 *
 * `getMimeType()` réclamait `symfony/mime`, absent de cette installation : il
 * levait une `LogicException` à chaque dépôt, et l'écran se rechargeait sans
 * l'image ni le moindre message. Le défaut a été trouvé en déposant une icône
 * de boutique, pas par un test — d'où ceux-ci.
 *
 * Lire les octets fait mieux que combler ce trou : un fichier qui se contente
 * de PORTER le nom d'une image est refusé, là où un devineur fondé sur
 * l'extension l'aurait accepté.
 */
final class PhotoStorageTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir() . '/merisu-photos-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $f) {
            unlink($f);
        }

        if (is_dir($this->dossier)) {
            rmdir($this->dossier);
        }
    }

    private function stockage(): PhotoStorage
    {
        return new PhotoStorage($this->dossier, '/uploads');
    }

    /**
     * Une vraie image, écrite pour de bon : un en-tête bricolé ne prouverait
     * rien, puisque `getimagesize` décode ce qui suit.
     *
     * Écrite à la main plutôt qu'avec `gd` : l'extension peut manquer sur le
     * serveur, et un test qui s'y saute en silence n'y vérifie plus rien —
     * or c'est précisément là que le dépôt d'images a échoué.
     */
    private static function png(int $largeur = 4, int $hauteur = 4): string
    {
        $lignes = '';
        for ($y = 0; $y < $hauteur; $y++) {
            $lignes .= "\x00" . str_repeat("\x80\x40\x60", $largeur);   // filtre 0, puis RVB
        }

        $bloc = static fn (string $type, string $donnees): string =>
            pack('N', \strlen($donnees)) . $type . $donnees . pack('N', crc32($type . $donnees));

        $chemin = tempnam(sys_get_temp_dir(), 'img') . '.png';
        file_put_contents($chemin,
            "\x89PNG\r\n\x1a\n"
            . $bloc('IHDR', pack('NNCCCCC', $largeur, $hauteur, 8, 2, 0, 0, 0))
            . $bloc('IDAT', (string) gzcompress($lignes))
            . $bloc('IEND', ''));

        return $chemin;
    }

    private static function depose(string $chemin, string $nomClient): UploadedFile
    {
        // `test: true` — sans quoi UploadedFile exige un vrai POST.
        return new UploadedFile($chemin, $nomClient, null, null, true);
    }

    public function testUneImageEstEnregistreeSousUnNomQuiNeVientPasDuClient(): void
    {
        // Le nom envoyé est HOSTILE : chemin remontant, double extension.
        $url = $this->stockage()->store(self::depose(self::png(), '../../evil.php.png'));

        self::assertMatchesRegularExpression('#^/uploads/[0-9a-f]{32}\.png$#', $url);
        self::assertFileExists($this->dossier . '/' . basename($url));
        self::assertStringNotContainsString('evil', $url);
    }

    /**
     * L'extension du client ne décide de RIEN.
     *
     * Un PNG déposé sous le nom « photo.jpg » reste un PNG : c'est le contenu
     * qui nomme le fichier écrit.
     */
    public function testLExtensionSuitLeContenuPasLeNom(): void
    {
        $url = $this->stockage()->store(self::depose(self::png(), 'photo.jpg'));

        self::assertStringEndsWith('.png', $url);
    }

    #[DataProvider('fichiersRefuses')]
    public function testCeQuiNEstPasUneImageEstRefuse(string $contenu, string $nom): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'faux');
        file_put_contents($chemin, $contenu);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UNSUPPORTED_IMAGE_TYPE');

        try {
            $this->stockage()->store(self::depose($chemin, $nom));
        } finally {
            @unlink($chemin);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function fichiersRefuses(): iterable
    {
        yield 'du PHP déguisé en image' => ['<?php echo "bonjour";', 'photo.png'];
        yield 'un texte quelconque' => ["bonjour\n", 'note.jpg'];
        yield 'un fichier vide' => ['', 'vide.png'];
        // L'en-tête seul ne suffit pas : `getimagesize` décode la suite.
        yield 'une signature PNG tronquée' => ["\x89PNG\r\n\x1a\n", 'tronque.png'];
    }

    public function testLesDimensionsSontRenduesPourPouvoirLesAnnoncer(): void
    {
        self::assertSame([12, 5], PhotoStorage::dimensions(self::png(12, 5)));
    }

    public function testLesDimensionsDUnNonImageSontNulles(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'faux');
        file_put_contents($chemin, 'pas une image');

        self::assertNull(PhotoStorage::dimensions($chemin));
        self::assertNull(PhotoStorage::dimensions('/n/existe/pas'));

        @unlink($chemin);
    }
}
