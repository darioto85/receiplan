<?php

namespace App\Service\Image\Storage;

interface ImageStorageInterface
{
    public function put(string $key, string $content, string $mimeType): void;
    public function exists(string $key): bool;
    public function delete(string $key): void;
    public function publicUrl(string $key): string;
}
