<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stockage des images déposées : photos de contrôle, icônes de boutique.
 *
 * Le type est lu DANS LES OCTETS, jamais dans le nom ni dans l'en-tête envoyé
 * par le client — l'un comme l'autre se choisissent librement. L'extension est
 * ensuite déduite du type reconnu : on n'écrit jamais un contenu arbitraire
 * sous un nom dicté par le navigateur.
 *
 * `getimagesize` plutôt que `getMimeType()` : cette dernière réclame
 * `symfony/mime`, absent de cette installation, et lève une `LogicException`
 * quand il manque. Le dépôt échouait donc à tous les coups — sans message,
 * l'écran se rechargeant simplement sans l'image. `getimagesize` fait mieux
 * que combler ce trou : il ne devine pas un type, il DÉCODE l'en-tête de
 * l'image, et rejette du même coup le fichier qui se contente d'en porter le
 * nom.
 */
final class PhotoStorage
{
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly string $uploadDir,
        private readonly string $publicPath,
    ) {
    }

    /** @return string URL publique de l'image enregistrée */
    public function store(UploadedFile $file): string
    {
        $extension = self::ALLOWED[self::imageType($file->getPathname())] ?? null;

        if ($extension === null) {
            throw new \RuntimeException('UNSUPPORTED_IMAGE_TYPE');
        }

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0o775, true) && !is_dir($this->uploadDir)) {
            throw new \RuntimeException('UPLOAD_DIR_UNAVAILABLE');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $file->move($this->uploadDir, $name);

        return $this->publicPath . '/' . $name;
    }

    /**
     * Les dimensions de l'image, ou null si le fichier n'en est pas une.
     *
     * L'appelant s'en sert pour DIRE ce qu'il va faire — « 480×200, recadrée
     * au centre » — plutôt que de recadrer en silence et laisser découvrir la
     * coupe à la connexion.
     *
     * @return array{int, int}|null largeur, hauteur
     */
    public static function dimensions(string $path): ?array
    {
        $taille = @getimagesize($path);

        return $taille === false ? null : [(int) $taille[0], (int) $taille[1]];
    }

    /** Le type de l'image tel que ses octets le déclarent, ou '' si ce n'en est pas une. */
    private static function imageType(string $path): string
    {
        // Le silence est voulu : un fichier qui n'est pas une image n'est pas
        // une anomalie du serveur, c'est une saisie à refuser proprement.
        $taille = @getimagesize($path);

        return $taille === false ? '' : (string) ($taille['mime'] ?? '');
    }

    /** Enregistre une image transmise en base64 (file d'attente hors-ligne). */
    public function storeBase64(string $dataUrl): string
    {
        if (preg_match('#^data:(image/(?:jpeg|png|webp));base64,#', $dataUrl, $m) !== 1) {
            throw new \RuntimeException('UNSUPPORTED_IMAGE_TYPE');
        }

        $binary = base64_decode(substr($dataUrl, \strlen($m[0])), true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('INVALID_PHOTO_DATA');
        }

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0o775, true) && !is_dir($this->uploadDir)) {
            throw new \RuntimeException('UPLOAD_DIR_UNAVAILABLE');
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$m[1]];
        file_put_contents($this->uploadDir . '/' . $name, $binary);

        return $this->publicPath . '/' . $name;
    }
}
