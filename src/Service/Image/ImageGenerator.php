<?php

namespace App\Service\Image;

use App\Service\Image\Storage\ImageStorageInterface;
use App\Service\AiImageClient;

final class ImageGenerator
{
    private const TARGET_SIZE = 200;

    // GPT image models: 1024/1536/auto (pas 512/256)
    private const GENERATE_SIZE = '1024x1024';

    // Réduction coût:
    private const IMAGE_MODEL = 'gpt-image-1-mini';
    private const IMAGE_QUALITY = 'low';

    // Sortie plus légère (moins de data à transférer / potentiellement moins de coût output)
    private const OUTPUT_FORMAT = 'webp';
    private const OUTPUT_COMPRESSION = 80; // 0-100
    private const BACKGROUND = 'opaque';   // opaque | transparent | auto

    public function __construct(
        private readonly AiImageClient $aiImageClient,
        private readonly ImageStorageInterface $storage,
    ) {}

    public function generateAndStore(ImageTargetInterface $target, object $entity, bool $overwrite = false): void
    {
        if (!$overwrite && $target->hasImage($entity)) {
            return;
        }

        $prompt = $target->buildPrompt($entity);

        $sourceBytes = $this->aiImageClient->generateImageBytes($prompt, [
            'model' => self::IMAGE_MODEL,
            'size' => self::GENERATE_SIZE,
            'quality' => self::IMAGE_QUALITY,
            'output_format' => self::OUTPUT_FORMAT,
            'output_compression' => self::OUTPUT_COMPRESSION,
            'background' => self::BACKGROUND,
        ]);

        // On garde ton format final PNG 200x200 fond blanc
        $finalPng200 = $this->resizeAndOptimizePng($sourceBytes, self::TARGET_SIZE);

        $key = $target->getStorageKey($entity);
        $this->storage->put($key, $finalPng200, 'image/png');
    }

    private function resizeAndOptimizePng(string $imageBytes, int $size): string
    {
        $src = imagecreatefromstring($imageBytes);
        if ($src === false) {
            throw new \RuntimeException('Impossible de lire l’image source (GD).');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $dst = imagecreatetruecolor($size, $size);

        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);

        $scale = min($size / $srcW, $size / $srcH);
        $newW = (int) round($srcW * $scale);
        $newH = (int) round($srcH * $scale);

        $dstX = (int) floor(($size - $newW) / 2);
        $dstY = (int) floor(($size - $newH) / 2);

        imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);

        ob_start();
        imagepng($dst, null, 9);
        $out = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if ($out === '') {
            throw new \RuntimeException('Échec génération PNG optimisé.');
        }

        return $out;
    }
}
