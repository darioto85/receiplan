<?php

namespace App\Service\Image;

use App\Service\AiImageClient;

final class ImageGenerator
{
    private const TARGET_SIZE = 200;
    private const GENERATE_SIZE = '1024x1024';

    public function __construct(
        private readonly AiImageClient $aiImageClient,
    ) {}

    public function generateAndStore(ImageTargetInterface $target, object $entity, bool $overwrite = false): void
    {
        if (!$overwrite && $target->hasImage($entity)) {
            return;
        }

        $prompt = $target->buildPrompt($entity);

        $png1024 = $this->aiImageClient->generatePng($prompt, self::GENERATE_SIZE);

        $finalPng200 = $this->resizeAndOptimizePng($png1024, self::TARGET_SIZE);

        $path = $target->getAbsolutePathForWrite($entity);

        $tmp = $path . '.' . uniqid('tmp_', true);
        file_put_contents($tmp, $finalPng200);
        @chmod($tmp, 0664);
        rename($tmp, $path);
    }

    private function resizeAndOptimizePng(string $pngBytes, int $size): string
    {
        $src = imagecreatefromstring($pngBytes);
        if ($src === false) {
            throw new \RuntimeException('Impossible de lire le PNG source (GD).');
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
