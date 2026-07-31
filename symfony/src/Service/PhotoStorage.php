<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stockage des photos de comptage.
 *
 * Le type MIME est vérifié en liste blanche, et l'extension est déduite de ce
 * type : on n'écrit jamais un contenu arbitraire sous un nom choisi par le client.
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

    /** @return string URL publique de la photo enregistrée */
    public function store(UploadedFile $file): string
    {
        $extension = self::ALLOWED[$file->getMimeType() ?? ''] ?? null;

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
