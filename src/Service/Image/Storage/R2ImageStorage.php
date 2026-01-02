<?php

namespace App\Image\Storage;

use League\Flysystem\FilesystemOperator;

final class R2ImageStorage implements ImageStorageInterface
{
    public function __construct(
        private readonly FilesystemOperator $fs,
        private readonly string $publicBaseUrl,
    ) {}

    public function put(string $key, string $content, string $mimeType): void
    {
        $this->fs->write($key, $content, [
            'visibility' => 'public', // utile même si r2.dev gère l’accès public
            'ContentType' => $mimeType,
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function exists(string $key): bool
    {
        return $this->fs->fileExists($key);
    }

    public function delete(string $key): void
    {
        if ($this->fs->fileExists($key)) {
            $this->fs->delete($key);
        }
    }

    public function publicUrl(string $key): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/' . ltrim($key, '/');
    }
}
